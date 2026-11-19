<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Decisione 536: le pagine dei debiti leggono ogni persona dalle sue righe. La regola, come prova, su scenari che rifanno i casi della quarta prova contabile
 * della 1.12.0-beta.12 (`docs/riferimenti/beta12_1_12_lavori/quarta/rapporto.md`) sul caso di Ettore Fabbri (`InteressiSulDebitoEreditatoScenario.php`): la
 * rata di aprile da € 300,00 scaduta il 30/04/2026, il decesso il 17/11/2026, Elena e Marco al 50 %, la successione registrata il 20/11/2026; il piano 2027 che
 * assorbe l'arretrato ha la rata 1 al 31/03/2027, emessa il 15/01/2027, con la voce da € 10,00 (la rata nuova di Ettore a credito: X2P, X2P10) o da € 1.000,00
 * (a debito: R2P, il caso vero di Bagnara).
 *
 * La regola, per ogni persona del caso, alla data, con «oggi» a quel giorno:
 * - (a) **come l'estratto conto**: la somma dei residui delle righe della lettura che le pagine usano («Rate scadute» con le righe «ereditato», e la stessa
 *   lettura a blocchi dei sei mesi e della tesoreria), meno il credito mostrato (`credito_disponibile`, quello di «Rate scadute» e delle lettere), più la sua
 *   parte dei saldi «non ancora in un piano» (per gestione e unità, senza la parte già mostrata come «ereditato»), è uguale al suo estratto conto: le righe del
 *   giornale sulle rate emesse più il Wallet non ancora nel giornale. Solo quando il Wallet non è a credito: un saldo a credito non ancora in un piano non
 *   lo mostra nessuna pagina (il defunto prima del piano, Elena con il conguaglio).
 * - (b) **la decisione 516**: per ogni erede vivo, la riga «ereditato» di ogni rata del defunto c'è solo finché la proposta conta interessi su quella chiave,
 *   ed è il capitale lordo della chiave alla data ({@see \App\Services\Gestionale\Accessori\DebitoEreditato::perEredi()}, la fonte della proposta); nella
 *   finestra fra l'emissione e il giorno in cui la rata nuova conta, al più quello che resta della rata nuova.
 * - (c) **lo storno dopo il piano** (Q4): il debito tornato all'erede con la coppia rovescia (libera, legata al passaggio) è in una sua riga «ereditato», non
 *   ancora in una rata.
 * - (d) **un versamento è dell'erede solo con la sua coppia** (lo scettico stretto della riscrittura, punto in testa): prima che un piano prenda il suo
 *   arretrato, le righe «ereditato» di un erede più quello che ha versato con la coppia nel Wallet legata al passaggio sono la sua parte della base al decesso.
 *   «Resta a …» con «Versato da» l'erede non ha la coppia: resta al defunto, come nel giornale e nel piano dopo.
 *
 * Con il codice di prima della riscrittura la regola è rossa in R2P e A1P (Q1), X2P (Q2), C3, D e N1UC (Q3), X2AP10S (Q4), e verde prima del piano, con
 * il versamento di Elena, nella finestra della 516 per gli eredi e nella catena.
 *
 * Che cosa NON copre: MySQL (qui sqlite); le pagine a video; le lettere come PDF (la lettura è la stessa); il riquadro dei sei mesi e la tesoreria come cifre
 * (si controlla che leggano le stesse righe, a blocchi); un erede che versa la rata nuova nella finestra della 516 (M-D14, prova a parte); più successioni
 * sulla stessa rata nuova, le pertinenze, «su tutte le rate», «continuano a suo nome» (decisione 537: fuori dal perimetro della riscrittura); il piano
 * approvato e non emesso per il defunto (il Wallet a credito).
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\Subentro;
use App\Services\Gestionale\Accessori\DebitoEreditato;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/**
 * Il Wallet di una persona non ancora nel giornale alla data: i saldi liberi o in un piano in bozza per gestione e unità (quelli della riga «non ancora in un
 * piano» di «Rate scadute»), e quelli presi da un piano la cui quota della persona su quell'unità non è ancora emessa; se è a credito (un gruppo sotto zero,
 * o una riga a credito libera che non è la coppia del versamento di un erede: il conguaglio dell'erede di riferimento, la contropartita del defunto).
 *
 * @return array{liberi: array<string, int>, presi_non_emessi: int, a_credito: bool}
 */
function ldrWallet(array $s, int $persona, string $alla): array
{
    $dopo = \Carbon\CarbonImmutable::parse($alla, 'UTC')->addDay()->format('Y-m-d');
    $righe = DB::table('saldi as s')->leftJoin('piani_rate as p', 'p.id', '=', 's.piano_rate_id')->where('s.condominio_id', $s['c']->id)->where('s.anagrafica_id', $persona)
        ->get(['s.gestione_id', 's.immobile_id', 's.saldo_iniziale', 's.is_applicato', 's.piano_rate_id', 's.passaggio_id', 'p.stato']);
    $liberi = [];
    $presi = 0;
    $aCredito = false;
    foreach ($righe as $r) {
        if (! $r->is_applicato || $r->stato === 'bozza') {
            $k = (int) $r->gestione_id . '|' . (int) $r->immobile_id;
            $liberi[$k] = ($liberi[$k] ?? 0) + (int) $r->saldo_iniziale;
            // Una riga a credito che non è la coppia di un versamento dell'erede (il conguaglio, la contropartita del defunto): nessuna pagina la mostra.
            $aCredito = $aCredito || ((int) $r->saldo_iniziale < 0 && $r->passaggio_id === null);

            continue;
        }
        $emessa = DB::table('rate_quote as q')->join('rate as r', 'r.id', '=', 'q.rata_id')->join('scritture_contabili as em', 'em.id', '=', 'q.scrittura_contabile_id')
            ->where('r.piano_rate_id', $r->piano_rate_id)->where('q.anagrafica_id', $persona)->where('q.immobile_id', $r->immobile_id)
            ->whereNull('em.deleted_at')->where('em.tipo_movimento', 'emissione_rata')->where('em.data_competenza', '<', $dopo)->exists();
        if (! $emessa) {
            $presi += (int) $r->saldo_iniziale;
        }
    }

    return ['liberi' => $liberi, 'presi_non_emessi' => $presi, 'a_credito' => $aCredito || $presi < 0 || array_filter($liberi, fn ($n) => $n < 0) !== []];
}

/** Le righe di una lettura come le confronta la regola: rata|unità|ereditato => residuo. */
function ldrRighe(?array $l): array
{
    $esito = [];
    foreach ($l['rate'] ?? [] as $r) {
        if ((int) $r['residuo'] === 0) {
            continue;
        }
        $k = (int) $r['rata_id'] . '|' . (int) $r['immobile_id'] . '|' . (isset($r['ereditato']) ? 'e' . (int) $r['ereditato']['subentro_id'] : '');
        $esito[$k] = ($esito[$k] ?? 0) + (int) $r['residuo'];
    }
    ksort($esito);

    return $esito;
}

/**
 * La regola alla data per le persone date per nome: le violazioni, una frase ciascuna (vuoto: tiene), e le cifre di ognuno (persona => estratto, pagine).
 *
 * @return array{violazioni: list<string>, cifre: array<string, array{estratto: ?int, pagine: int, credito: int}>}
 */
function ldrRegola($test, array $s, string $alla, array $chi): array
{
    $test->travelTo($alla . ' 12:00:00');
    $cid = (int) $s['c']->id;
    $lettura = app(LetturaDebiti::class);
    $grezze = $lettura->perCondominio($cid, $alla);
    $de = new DebitoEreditato();
    $pagine = $de->sullaLettura($cid, $alla, null, $grezze, null);
    $mostrati = $de->perLaLettura($cid, $alla)['saldi_mostrati_per'];
    // La lettura a blocchi dei sei mesi e della tesoreria (blocchi piccoli, per passare dai confini).
    $aBlocchi = [];
    foreach ((new DebitoEreditato())->aBlocchi($lettura->perCondominioABlocchi($cid, $alla, 2), $cid, $alla, null) as $blocco) {
        foreach ($blocco as $id => $l) {
            $aBlocchi[(int) $id] = $l;
        }
    }
    $violazioni = [];
    $cifre = [];
    foreach ($chi as $nome) {
        $id = (int) $s['persone'][$nome]->id;
        $w = ldrWallet($s, $id, $alla);
        $giornale = (int) collect($grezze[$id]['rate'] ?? [])->sum(fn ($r) => (int) $r['dovuto'] - (int) $r['versato']);
        $residui = (int) collect($pagine[$id]['rate'] ?? [])->sum('residuo');
        $credito = (int) ($pagine[$id]['credito_disponibile']['totale'] ?? 0);
        $walletMostrato = 0;
        foreach ($w['liberi'] as $g => $netto) {
            $walletMostrato += max(0, $netto - (int) ($mostrati[$id . '|' . $g] ?? 0));
        }
        $aCredito = $w['a_credito'];
        $estratto = $giornale + (int) array_sum($w['liberi']) + $w['presi_non_emessi'];
        $mostra = $residui - $credito + $walletMostrato;
        $cifre[$nome] = ['estratto' => $aCredito ? null : $estratto, 'pagine' => $mostra, 'credito' => $credito];
        if (! $aCredito && $mostra !== $estratto) {
            $violazioni[] = sprintf('%s: estratto %d, le pagine %d (residui %d, credito %d, saldi non ancora in un piano %d)', $nome, $estratto, $mostra, $residui, $credito, $walletMostrato);
        }
        if (ldrRighe($pagine[$id] ?? null) !== ldrRighe($aBlocchi[$id] ?? null)) {
            $violazioni[] = sprintf('%s: la lettura a blocchi (sei mesi, tesoreria) non è quella di «Rate scadute»', $nome);
        }
        // (c) Q4: il debito tornato all'erede con la coppia rovescia.
        $tornati = (int) DB::table('saldi as s')->join('subentri as p', 'p.id', '=', 's.passaggio_id')->where('s.condominio_id', $cid)->where('s.anagrafica_id', $id)
            ->where('p.anagrafica_uscente_id', '!=', $id)->where('s.is_applicato', false)->where('s.saldo_iniziale', '>', 0)->sum('s.saldo_iniziale');
        $fuori = (int) collect($pagine[$id]['rate'] ?? [])->sum(fn ($r) => (int) ($r['ereditato']['fuori_rata'] ?? 0));
        if ($tornati > 0 && $fuori < $tornati) {
            $violazioni[] = sprintf('%s: %d tornati con lo storno, %d nelle righe «ereditato» non ancora in una rata', $nome, $tornati, $fuori);
        }
    }
    // (b) La decisione 516, per gli eredi vivi del caso.
    $successioni = Subentro::query()->where('condominio_id', $cid)->where('tipo_passaggio', 'successione')->whereNull('subentro_padre_id')->get();
    $morti = $successioni->filter(fn (Subentro $x) => $x->decorrenza->format('Y-m-d') <= $alla)->pluck('anagrafica_uscente_id')->map(fn ($x) => (int) $x)->all();
    $eredi = [];
    foreach ($chi as $nome) {
        $id = (int) $s['persone'][$nome]->id;
        $diQualcuno = $successioni->contains(fn (Subentro $x) => $x->decorrenza->format('Y-m-d') <= $alla
            && collect($x->registro['eredi'] ?? [])->contains(fn ($e) => (int) ($e['anagrafica_id'] ?? 0) === $id));
        if ($diQualcuno && ! in_array($id, $morti, true)) {
            $eredi[$nome] = $id;
        }
    }
    if ($eredi !== []) {
        $regole = (new \App\Services\Gestionale\Accessori\RegoleDelRecupero())->delCondominio($cid);
        $coperte = app(\App\Services\Gestionale\Debiti\AccordiRientro::class)->senzaInteressi($cid, array_values($eredi), $alla);
        $proposta = app(\App\Services\Gestionale\Accessori\PropostaInteressi::class);
        $chiavi = (new DebitoEreditato())->perEredi($cid, $alla, array_values($eredi), $regole, $coperte,
            fn (array $defunti) => $proposta->giornaleDelDefunto($cid, $alla, $defunti, $regole));
        foreach ($eredi as $nome => $id) {
            $attese = [];
            foreach ($chiavi[$id]['chiavi'] ?? [] as $k => $c) {
                if (($c['tipo'] ?? null) !== 'ereditata') {
                    continue;
                }
                // Il capitale che resta alla fine del giorno: un movimento conta dal giorno dopo, e l'uscita nella rata nuova del giorno stesso non toglie la
                // riga quel giorno (la chiave conta fino a lì); un versamento del giorno sì.
                $capitale = (int) collect($chiavi[$id]['movimenti'])->where('chiave', $k)
                    ->filter(fn ($m) => $m['giorno'] < $alla || ($m['causa'] ?? null) === 'versamento')->sum('importo');
                $rk = (int) $c['rata']->id . '|' . (int) $c['immobile_id'];
                $attese[$rk] = ($attese[$rk] ?? 0) + $capitale;
            }
            // Nella finestra la riga è al più quello che resta delle rate nuove che hanno preso l'arretrato dell'erede.
            $pianiSuoi = DB::table('saldi')->where('anagrafica_id', $id)->where('is_applicato', true)->whereNotNull('piano_rate_id')->pluck('piano_rate_id')->unique()->all();
            $nuove = collect($grezze[$id]['rate'] ?? [])->filter(fn ($r) => in_array((int) $r['piano_rate_id'], array_map('intval', $pianiSuoi), true));
            $libero = $nuove->isEmpty() ? null : (int) $nuove->sum('residuo');
            $attese = array_filter($attese, fn ($c) => $c > 0);
            $lette = [];
            foreach ($pagine[$id]['rate'] ?? [] as $r) {
                if (isset($r['ereditato']) && (int) $r['residuo'] > 0) {
                    $rk = (int) $r['rata_id'] . '|' . (int) $r['immobile_id'];
                    $lette[$rk] = ($lette[$rk] ?? 0) + (int) $r['residuo'];
                }
            }
            // (d) Prima del piano: righe «ereditato» + versato con la coppia = la parte della base al decesso.
            $prePiano = ! DB::table('saldi')->where('anagrafica_id', $id)->whereNotNull('subentro_id')->where('is_applicato', true)->exists();
            if ($prePiano) {
                $parte = (int) collect($chiavi[$id]['chiavi'] ?? [])->filter(fn ($c) => ($c['tipo'] ?? null) === 'ereditata')->sum(fn ($c) => (int) ($c['ereditata']['parte'] ?? 0));
                $conLaCoppia = -(int) DB::table('saldi')->where('condominio_id', $cid)->where('anagrafica_id', $id)->whereNotNull('passaggio_id')->sum('saldo_iniziale');
                if ((int) array_sum($lette) + $conLaCoppia !== $parte) {
                    $violazioni[] = sprintf('%s: righe «ereditato» %d + versato con la coppia %d, la parte al decesso %d', $nome, array_sum($lette), $conLaCoppia, $parte);
                }
            }
            ksort($attese);
            ksort($lette);
            // Oltre quello che resta delle rate nuove, il confronto è sul totale (le parti escono dalle rate del defunto più vecchie).
            if ($libero !== null && array_sum($attese) > $libero) {
                $bene = (int) array_sum($lette) === $libero && array_diff_key($lette, $attese) === [];
            } else {
                $bene = $attese === $lette;
            }
            if (! $bene) {
                $violazioni[] = sprintf('%s: le chiavi ereditate della proposta %s, le righe «ereditato» %s', $nome, json_encode($attese), json_encode($lette));
            }
        }
    }

    return ['violazioni' => $violazioni, 'cifre' => $cifre];
}

/** «Resta a Ettore»: un versamento di Ettore sulla rata data dopo la successione, senza il legame agli eredi; con «Versato da» la persona data per nome. */
function ldrRestaAEttore($test, array $s, int $quotaId, int $cent, string $data, ?string $versatoDa = null): void
{
    $test->travelTo($data . ' 11:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Neri']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data, 'importo_totale' => number_format($cent / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $quotaId, 'importo' => number_format($cent / 100, 2, '.', '')]],
        'agli_eredi' => '0',
    ] + ($versatoDa !== null ? ['versato_da_id' => $s['persone'][$versatoDa]->id] : []))->assertSessionHasNoErrors();
}

/** L'erede pagante sul suo debito ereditato, a nome suo: restituisce la scrittura dell'incasso. */
function ldrVersaErede($test, array $s, string $chi, Subentro $p, int $cent, string $data): \App\Models\Gestionale\ScritturaContabile
{
    $test->travelTo($data . ' 11:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data, 'importo_totale' => number_format($cent / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [],
        'debito_ereditato' => [['subentro_id' => $p->id, 'importo' => number_format($cent / 100, 2, '.', '')]],
    ])->assertSessionHasNoErrors();

    return \App\Models\Gestionale\ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();
}

/** La quota di Ettore sulla rata data del piano di Via Verdi (di serie la rata di aprile). */
function ldrQuotaEttore(array $s, int $numero = 1): RataQuote
{
    return RataQuote::where('rata_id', lcdRata($s, $numero))->where('anagrafica_id', $s['persone']['Neri']->id)->where('immobile_id', $s['unita']['U1']->id)->firstOrFail();
}

/** La seconda successione: Marco muore il 01/12/2026 e lascia la sua parte di U1 a Luca (registrata il 05/12/2026). */
function ldrSeconda($test, array &$s): Subentro
{
    $s['persone']['Luca'] = ideErede($s, 'Luca');
    $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['Marco']->id)->where('immobile_id', $s['unita']['U1']->id)->where('attivo', true)->value('id');
    $quota = (float) DB::table('anagrafica_immobile')->where('id', $riga)->value('quota');
    $test->travelTo('2026-12-05 10:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), [
        'tipo' => 'successione', 'riga_uscente_id' => $riga, 'decorrenza' => '2026-12-01', 'quota' => $quota, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $s['persone']['Luca']->id, 'quota' => $quota]], 'arretrato' => Subentro::ARRETRATO_AGLI_EREDI,
        'erede_di_riferimento' => $s['persone']['Luca']->id, 'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 2', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta'])->assertSessionHasNoErrors();

    return Subentro::where('immobile_id', $s['unita']['U1']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

const LDR_TRE = ['Neri', 'Elena', 'Marco'];

it('la regola prima del piano (R2): Elena e Marco € 150,00 «ereditato» ciascuno, Ettore senza niente; com\'è l\'estratto conto', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    expect(ldrRegola($this, $s, '2026-12-31', LDR_TRE)['violazioni'])->toBe([]);
});

it('la regola con il versamento di Elena prima del piano (A1): la sua riga si chiude, quella di Marco resta', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    ldrVersaErede($this, $s, 'Elena', $p, 15000, '2026-11-25');
    expect(ldrRegola($this, $s, '2026-12-31', LDR_TRE)['violazioni'])->toBe([]);
});

it('la regola con «Resta a Ettore» prima del piano (C3, Q3): gli eredi devono ancora la loro parte intera', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    ldrRestaAEttore($this, $s, ldrQuotaEttore($s)->id, 5000, '2026-11-25');
    // Con il codice di prima: Elena e Marco € 125,00 «ereditato» (il versamento di Ettore portava via € 25,00 a testa), la proposta € 150,00.
    expect(ldrRegola($this, $s, '2026-12-31', LDR_TRE)['violazioni'])->toBe([]);
});

it('la regola dopo il piano con la rata nuova di Ettore a debito (R2P, Q1): Ettore deve € 438,36, le pagine lo dicono', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    idePianoNuovo($this, $s, voceCents: 100000);
    $r = ldrRegola($this, $s, '2027-04-30', LDR_TRE);
    // Con il codice di prima: le pagine € 138,36 (la rata nuova di Ettore € 438,36 − € 300,00 di contropartita, e la rata di aprile nascosta).
    expect($r['violazioni'])->toBe([])->and($r['cifre']['Neri'])->toBe(['estratto' => 43836, 'pagine' => 43836, 'credito' => 0]);
    // Nella finestra della 516 lo stesso: la rata di aprile degli eredi, la rata nuova di Ettore intera.
    expect(ldrRegola($this, $s, '2027-02-20', LDR_TRE)['violazioni'])->toBe([]);
    $this->travelTo('2027-04-30 12:00:00');
    // «Rate scadute», i sei mesi e la tesoreria: la stessa cifra.
    $elenco = app(\App\Services\Gestionale\Debiti\ElencoRateScadute::class);
    $riga = $elenco->righe($s['c'], $elenco->filtriNormalizzati(['alla' => '2027-04-30']))->firstWhere('anagrafica_id', $s['persone']['Neri']->id);
    $tesoreria = collect(app(\App\Services\Treasury\TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray()['morositaImpattanti'])->pluck('importoCents', 'anagraficaId');
    expect($riga['importo'] ?? null)->toBe(43836)->and($riga['credito'] ?? null)->toBe(0)->and($tesoreria[$s['persone']['Neri']->id] ?? null)->toBe(43836);
});

it('la regola dopo il piano con il versamento di Elena di prima (A1P, Q1)', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    ldrVersaErede($this, $s, 'Elena', $p, 15000, '2026-11-25');
    idePianoNuovo($this, $s, voceCents: 100000);
    $r = ldrRegola($this, $s, '2027-04-30', LDR_TRE);
    expect($r['violazioni'])->toBe([])->and($r['cifre']['Neri']['pagine'])->toBe(43836);
});

it('la regola dopo il piano con la rata nuova di Ettore a credito (X2P, Q2): nessun credito finto, nella finestra e dopo', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    idePianoNuovo($this, $s);
    // Con il codice di prima: Ettore € 4,38 da chiedere e un credito di € 295,62 accanto (la contropartita, già usata a video).
    $finestra = ldrRegola($this, $s, '2027-02-01', LDR_TRE);
    $dopo = ldrRegola($this, $s, '2027-04-30', LDR_TRE);
    expect($finestra['violazioni'])->toBe([])->and($dopo['violazioni'])->toBe([])
        ->and($dopo['cifre']['Neri'])->toBe(['estratto' => 438, 'pagine' => 438, 'credito' => 0]);
    // La finestra delle lettere: il sollecito a Ettore chiede € 4,38, senza un credito accanto («Togli chi ha un credito che copre la lettera» non lo toglie).
    $ettore = $s['persone']['Neri']->id;
    $lettera = app(\App\Services\Gestionale\Lettere\EmissioneLettere::class)->contenuti($s['c'], 'sollecito', ['persone' => [$ettore]])['persone'][$ettore] ?? null;
    expect($lettera['totale'] ?? null)->toBe(438)->and($lettera['credito'] ?? null)->toBe(0);
});

/*
 * La finestra della 516 per gli eredi: verde anche con il codice di prima. Il defunto dopo l'emissione è nelle prove di Q1 e Q2 (rosso con il codice di
 * prima in ogni stato dopo l'emissione: la sua rata nuova è a debito o a credito).
 */

it('la regola nella finestra della 516 e dopo, per gli eredi (senza giorni di «Interessi dopo la scadenza»)', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    idePianoNuovo($this, $s, voceCents: 100000);
    foreach (['2027-02-20', '2027-03-31', '2027-04-01'] as $giorno) {
        expect(ldrRegola($this, $s, $giorno, ['Elena', 'Marco'])['violazioni'])->toBe([]);
    }
});

it('la regola nella finestra della 516 per gli eredi, con 10 giorni di «Interessi dopo la scadenza», e con la scelta che ferma il debito ereditato alla scadenza', function () {
    $s = ideCaso($this, 10);
    ideRegistra($this, $s, ideSuccessione($s));
    idePianoNuovo($this, $s, voceCents: 100000);
    foreach (['2027-04-05', '2027-04-11'] as $giorno) {
        expect(ldrRegola($this, $s, $giorno, ['Elena', 'Marco'])['violazioni'])->toBe([]);
    }
    \App\Models\ImpostazioniCondominio::updateOrCreate(['condominio_id' => $s['c']->id],
        [\App\Services\Gestionale\Accessori\RegoleDegliEredi::SCELTE['tolleranza_rata_nuova'][0] => true]);
    expect(ldrRegola($this, $s, '2027-04-05', ['Elena', 'Marco'])['violazioni'])->toBe([]);
});

it('la regola con «Resta a Ettore» dopo il piano, nella finestra (D, Q3): Marco deve ancora la sua parte intera', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    idePianoNuovo($this, $s);
    ldrRestaAEttore($this, $s, ldrQuotaEttore($s)->id, 10000, '2027-02-20');
    // Con il codice di prima: Marco € 100,00 «ereditato» (− € 50,00), la proposta € 150,00.
    $r = ldrRegola($this, $s, '2027-02-25', LDR_TRE);
    expect($r['violazioni'])->toBe([]);
});

it('la regola dopo «Usa credito» di Ettore per tutta la rata di aprile (N1UC, Q3): la riga «ereditato» di Marco resta', function () {
    $s = ideCaso($this);
    $this->travelTo('2026-10-01 10:00:00');
    lcdEmetti($this, $s, [4], '2026-10-01');
    ideRegistra($this, $s, ideSuccessione($s));
    $piano = idePianoNuovo($this, $s);
    $ettore = $s['persone']['Neri']->id;
    $vecchia = ldrQuotaEttore($s);
    $nuova = RataQuote::whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $piano->id)->pluck('id'))->where('anagrafica_id', $ettore)->firstOrFail();
    $this->travelTo('2027-02-02 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $ettore, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2027-02-02', 'importo_totale' => '0.00', 'descrizione' => 'Compensazione', 'eccedenza' => '0.00',
        'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $vecchia->id, 'importo' => '300.00'], ['rata_id' => $nuova->id, 'importo' => '-300.00']],
    ])->assertSessionHasNoErrors();
    expect((int) $vecchia->fresh()->importo_pagato)->toBe(30000);
    // Con il codice di prima: la rata di aprile a saldo zero usciva dalla lettura, e Marco perdeva € 150,00 «ereditato».
    expect(ldrRegola($this, $s, '2027-02-03', LDR_TRE)['violazioni'])->toBe([]);
});

it('la regola dopo lo storno del versamento di Marco, dopo il piano (X2AP10S, Q4): il debito tornato è suo, non ancora in una rata', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    $incasso = ldrVersaErede($this, $s, 'Marco', $p, 15000, '2026-11-25');
    idePianoNuovo($this, $s);
    $this->travelTo('2027-02-01 10:00:00');
    app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute($incasso, $s['c'], \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    expect(DB::table('saldi')->where('passaggio_id', $p->id)->where('is_applicato', false)->where('anagrafica_id', $s['persone']['Marco']->id)->value('saldo_iniziale'))->toBe(15000);
    // Con il codice di prima: i € 150,00 solo fra i saldi «non ancora in un piano», non nella riga di Marco né nel sollecito; la proposta li toglieva dal 01/04.
    foreach (['2027-02-03', '2027-04-30'] as $giorno) {
        expect(ldrRegola($this, $s, $giorno, LDR_TRE)['violazioni'])->toBe([]);
    }
    // La proposta: dopo il 31/03 i € 150,00 tornati restano sulla chiave ereditata di Marco, con i loro interessi (prima uscivano nella rata nuova).
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2027-04-30'), $s, 'Marco');
    expect($r['al'])->toBe('2027-04-30')->and(collect($r['tratti'])->last()['capitale'])->toBe(15000);
});

it('la regola nella catena (K): Marco muore il 01/12/2026, la sua parte è di Luca', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    ldrSeconda($this, $s);
    expect(ldrRegola($this, $s, '2026-12-31', [...LDR_TRE, 'Luca'])['violazioni'])->toBe([]);
});

/*
 * Lo scettico stretto della riscrittura (punto in testa): «Resta a …» con «Versato da» un erede. La riga del giornale ha il legame all'erede (la riga di «Versato
 * da» della Coda 167), ma nessuna coppia nel Wallet: il versamento resta al defunto, e il piano dopo chiede all'erede l'arretrato intero (decisione 529). Le
 * pagine e la proposta lo leggono allo stesso modo.
 */

it('la regola con «Resta a Ettore» e «Versato da» Marco, prima del piano (R2 dello scettico): Marco deve ancora la sua parte intera', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    ldrRestaAEttore($this, $s, ldrQuotaEttore($s)->id, 5000, '2026-11-25', 'Marco');
    expect(DB::table('saldi')->whereNotNull('passaggio_id')->exists())->toBeFalse();
    // Con il codice di prima: Marco € 100,00 «ereditato» (− € 50,00) con l'estratto invariato, e la proposta toglieva i € 50,00 dal 26/11.
    expect(ldrRegola($this, $s, '2026-12-31', LDR_TRE)['violazioni'])->toBe([]);
});

it('la regola con «Resta a Ettore» e «Versato da» Luca, nella catena (K dello scettico): Luca deve ancora la parte che gli arriva da Marco', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    ldrSeconda($this, $s);
    ldrRestaAEttore($this, $s, ldrQuotaEttore($s)->id, 5000, '2026-12-10', 'Luca');
    // Con il codice di prima: Luca € 100,00 «ereditato» invece di € 150,00.
    expect(ldrRegola($this, $s, '2026-12-31', [...LDR_TRE, 'Luca'])['violazioni'])->toBe([]);
});
