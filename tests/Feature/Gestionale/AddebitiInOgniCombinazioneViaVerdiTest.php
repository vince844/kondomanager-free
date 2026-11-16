<?php

/**
 * Gli addebiti a una persona sola in ogni combinazione, su Via Verdi (`AddebitiScenario.php`, `AccessoriScenario.php`): le porte del piano e del conguaglio
 * per i giorni senza titolare, gli interessi di mora, l'accordo di rientro, la successione con gli interessi aperti. Ogni caso si legge in tutte le viste
 * (`AddebitiInOgniCombinazioneScenario.php`) prima e dopo, e le differenze sono quelle fatte a mano. I controlli di Via Verdi non tornano tutti per
 * costruzione (la banca aperta senza la scrittura di apertura): devono restare come erano.
 *
 * Le quote non assegnate di U3 (Verdi esce il 30/11 e nessuno subentra): Pulizie € 3.000,00 × 200/1000 × 31/365 = 50,9589 → € 50,96 e Manutenzioni
 * € 1.000,00 × 200/1000 × 31/365 = 16,9863 → € 16,99, in tutto € 67,95; nel consuntivo gli stessi giorni sono € 56,05 + € 15,29 = € 71,34, e restano € 3,39.
 * Gli interessi di Neri al 29/06: € 300,00 × 1,60 % × 30/365 = 0,3945 (dal 01/04 al 30/04) + € 200,00 × 1,60 % × 60/365 = 0,5260 (dal 01/05 al 29/06) =
 * 0,9205 → € 0,92. Centesimi.
 */

use App\Models\Esercizio;
use App\Services\Gestionale\Accessori\AccessoriDaIncassare;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccordoCalendarioScenario.php';
require_once __DIR__ . '/AddebitiInOgniCombinazioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Le viste dell'esercizio lette il 15/01/2027 (o il giorno dato), e l'estratto delle persone date. */
function aovViste($test, array $s, ?Esercizio $e = null, array $chi = ['Neri', 'Bianchi', 'Verdi'], string $giorno = '2027-01-15 10:00:00'): array
{
    $test->travelTo($giorno);

    return aocRendiconto($test, $s, $e ?? $s['e']) + ['estratti' => collect($chi)->mapWithKeys(fn ($n) => [$n => aocEstratto($test, $s, $n)])->all()];
}

/** La differenza fra due letture, cifra per cifra, delle sole cifre che cambiano. */
function aovDifferenza(array $prima, array $dopo): array
{
    $esito = [];
    foreach ($dopo as $k => $v) {
        if (is_array($v)) {
            $d = aovDifferenza($prima[$k] ?? [], $v);
            if ($d !== []) {
                $esito[$k] = $d;
            }
        } elseif (is_int($v) && $v !== ($prima[$k] ?? 0)) {
            $esito[$k] = $v - (int) ($prima[$k] ?? 0);
        } elseif (! is_int($v) && $v !== ($prima[$k] ?? null)) {
            $esito[$k] = [$prima[$k] ?? null, $v];
        }
    }

    return $esito;
}

/** Via Verdi con U3 senza titolare a dicembre, le rate emesse e versate, le spese del consuntivo che la Sintesi legge. */
function aovSenzaTitolare($test): array
{
    $s = adbVerdiFinoANovembre($test);
    cpcRateEIncassi($test, $s);
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    cpcSpese($s);

    return $s;
}

/** Le quote non assegnate di U3 addebitate dalla proposta del piano, alla persona che la proposta mette per prima. */
function aovDalPiano($test, array $s, ?int $gestioneId = null, ?int $esercizioId = null): array
{
    $quote = adbQuoteNonAssegnate($s);
    $p = $test->actingAs($test->user)->getJson(route('admin.gestionale.addebiti.proposta', [$s['c']]) . '?' . http_build_query(['piano_rate_id' => $s['piano']->id,
        'chiavi' => [$quote['Pulizie']['chiave'], $quote['Manutenzioni']['chiave']]]))->assertOk()->json();
    $chi = collect($s['persone'])->search(fn ($a) => (int) $a->id === (int) $p['persone'][0]['anagrafica_id']);
    $rate = aocAddebita($test, $s, array_map(fn (array $v) => ['chi' => $chi, 'unita' => 'U3', 'euro' => number_format($v['resta_cents'] / 100, 2, ',', ''),
        'causale' => $v['causale'], 'chiave' => $v['chiave'], 'piano_origine_id' => $v['piano_origine_id'], 'scadenza' => $p['scadenza']], $p['voci']), $gestioneId, $esercizioId)
        ->assertCreated()->json('rate');

    return [$p, $rate];
}

/** Le spese non attribuite della scheda «Conguaglio», per la gestione: coperti, non attribuite, restano. */
function aovCoperti($test, array $s, Esercizio $e, int $gestioneId): array
{
    $g = collect($test->actingAs($test->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $e]))->assertOk()->viewData('page')['props']['conguaglio']['gestioni'])
        ->firstWhere('id', $gestioneId);

    return [$g['addebiti']['coperti'], $g['addebiti']['non_attribuite'], $g['addebiti']['restano']];
}

// ─── Le porte del piano e del conguaglio ───────────────────────────────────────────────────────────────────────────────

it('dal piano: le quote non assegnate di U3, € 50,96 e € 16,99, a Verdi; poi dalla scheda «Conguaglio» i € 3,39 che restano: ogni vista dice € 67,95 e poi € 71,34, il conguaglio di Verdi no', function () {
    $s = aovSenzaTitolare($this);
    $prima = aovViste($this, $s);
    expect(aovCoperti($this, $s, $s['e'], $s['g']->id))->toBe([0, 7134, 7134]);

    $this->travelTo('2026-11-02 10:00:00');
    [$p, $rate] = aovDalPiano($this, $s);
    // La proposta: Verdi, titolare prima dei giorni senza titolare; gli importi che restano.
    expect($p['persone'][0]['nome'])->toBe('Verdi')
        ->and(array_column($p['voci'], 'resta_cents'))->toBe([5096, 1699])
        ->and(array_map(fn ($id) => aocScrittura($s, (int) aocQuota($id)->scrittura_contabile_id, $id)['righe'], $rate))->toBe([
            [['crediti_condomini', 'dare', 5096, 'Verdi', 'U3', true], ['gestione_rate', 'avere', 5096, null, null, false]],
            [['crediti_condomini', 'dare', 1699, 'Verdi', 'U3', true], ['gestione_rate', 'avere', 1699, null, null, false]],
        ]);
    $dopo = aovViste($this, $s);
    $g = $s['g']->id;
    // 50,96 + 16,99 = 67,95 in ogni vista: estratto, riga «addebiti», raccordo, quote a giornale, risultato, crediti, «Gestione rate», posizione e saldo di Verdi.
    expect(aovDifferenza($prima, $dopo))->toBe([
        'riepilogo' => ['righe' => [$g => ['addebiti' => [6795, 6795]]], 'contributi' => 6795, 'quote' => 6795, 'risultato' => 6795],
        'situazione' => ['crediti' => 6795, 'gestione_rate' => 6795],
        'conguaglio' => ['Verdi' => [$g => [4 => 6795, 5 => 6795]]],
        'estratti' => ['Verdi' => ['addebiti' => 6795, 'saldo' => 6795, 'lista' => 6795]],
    ])->and(aovCoperti($this, $s, $s['e'], $g))->toBe([6795, 7134, 339]);

    // La porta del conguaglio: la proposta per U3, Verdi, e la causale; i € 3,39.
    $c = $this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.proposta', [$s['c']]) . '?' . http_build_query(['gestione_id' => $g, 'esercizio_id' => $s['e']->id,
        'immobile_id' => $s['unita']['U3']->id]))->assertOk()->json();
    expect([$c['persone'][0]['nome'], $c['causale']])->toBe(['Verdi', 'Spese dei giorni senza titolare, Ordinaria 2026']);
    aocAddebita($this, $s, [['chi' => 'Verdi', 'unita' => 'U3', 'euro' => '3,39', 'causale' => $c['causale'], 'scadenza' => $c['scadenza'],
        'gestione_origine_id' => $g, 'esercizio_origine_id' => $s['e']->id]])->assertCreated();
    $ancora = aovViste($this, $s);
    // 67,95 + 3,39 = 71,34, quanto le spese non attribuite: non resta niente.
    expect(aovDifferenza($prima, $ancora))->toBe([
        'riepilogo' => ['righe' => [$g => ['addebiti' => [7134, 7134]]], 'contributi' => 7134, 'quote' => 7134, 'risultato' => 7134],
        'situazione' => ['crediti' => 7134, 'gestione_rate' => 7134],
        'conguaglio' => ['Verdi' => [$g => [4 => 7134, 5 => 7134]]],
        'estratti' => ['Verdi' => ['addebiti' => 7134, 'saldo' => 7134, 'lista' => 7134]],
    ])->and(aovCoperti($this, $s, $s['e'], $g))->toBe([7134, 7134, 0]);
});

it('dal piano, a cavallo d\'anno: con il 2026 chiuso le quote si addebitano il 10/03/2027 nel 2027; il 2026 le vede coperte e non cambia, il 2027 ha la riga sua', function () {
    $s = aovSenzaTitolare($this);
    $prima = aovViste($this, $s);
    [$e27, $g27] = adbAnnoDopo($s, 'chiuso');
    $this->travelTo('2027-03-10 10:00:00');
    [$p, $rate] = aovDalPiano($this, $s, $g27->id, $e27->id);
    expect($p['dove']['proposta'])->toMatchArray(['gestione_id' => (int) $g27->id, 'esercizio_id' => (int) $e27->id])
        ->and(aocScrittura($s, (int) aocQuota($rate[0])->scrittura_contabile_id, $rate[0]))->toMatchArray(['gestione' => (int) $g27->id, 'esercizio' => (int) $e27->id, 'giorno' => '2027-03-10']);

    // Il 2026, chiuso: nessuna cifra cambia; la scheda dice coperti € 67,95.
    $dopo26 = aovViste($this, $s, null, [], '2027-03-10 12:00:00');
    expect(aovDifferenza(array_diff_key($prima, ['estratti' => 1]), array_diff_key($dopo26, ['estratti' => 1])))->toBe([])
        ->and(aovCoperti($this, $s, $s['e'], $s['g']->id))->toBe([6795, 7134, 339]);
    // Il 2027: la riga «addebiti» della sua ordinaria e i contributi; l'estratto conto dell'esercizio aperto.
    $v27 = aovViste($this, $s, $e27, ['Verdi'], '2027-03-10 12:00:00');
    expect($v27['riepilogo']['righe'])->toBe([$g27->id => ['addebiti' => [6795, 6795]]])
        ->and($v27['riepilogo']['contributi'])->toBe(6795)
        ->and($v27['estratti']['Verdi']['lista'])->toBe(6795);
});

// ─── Gli interessi di mora ─────────────────────────────────────────────────────────────────────────────────────────────

it('gli interessi di Neri al 29/06, € 0,92 dalla proposta di «Rate scadute»: su «Accessori da incassare», fuori dalle spese; versati vanno a «Interessi di mora» e riducono le spese di tutti; lo storno li rovescia', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-04-30 10:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');
    $prima = aovViste($this, $s);

    $this->travelTo('2026-06-29 10:00:00');
    $proposta = accProposta($this, $s, ['Neri'])->assertOk()->json();
    expect($proposta['totale'])->toBe(92);
    $rataId = (int) accRegistra($this, $s, $proposta)->assertCreated()->json('rate.0');
    expect(\App\Models\Gestionale\Rata::findOrFail($rataId)->descrizione)->toBe('Interessi di mora al 29/06/2026')
        ->and(aocScrittura($s, (int) aocQuota($rataId)->scrittura_contabile_id, $rataId))->toBe([
            'righe' => [['crediti_condomini', 'dare', 92, 'Neri', 'U1', true], [AccessoriDaIncassare::RUOLO, 'avere', 92, null, null, false]],
            'gestione' => (int) $s['g']->id, 'esercizio' => (int) $s['e']->id, 'giorno' => '2026-06-29',
        ]);
    $dopo = aovViste($this, $s);
    $g = $s['g']->id;
    // Il credito e la sua contropartita: nessuna quota, nessun costo, nessuna spesa di nessuno cambia.
    expect(aovDifferenza($prima, $dopo))->toBe([
        'situazione' => ['crediti' => 92, 'accessori' => 92],
        'conguaglio' => ['Neri' => [$g => [4 => 92, 5 => 92]]],
        'estratti' => ['Neri' => ['addebiti' => 92, 'saldo' => 92, 'lista' => 92]],
    ]);

    // Versati il 10/07: alla voce, la riga di cassa una volta sola.
    $this->travelTo('2026-07-10 10:00:00');
    $incasso = lcdIncassa($this, $s, 'Neri', [aocQuota($rataId)->id => 92], '2026-07-10');
    $mastro = DB::table('conti_contabili')->where('id', $s['interessi']->conto_contabile_id)->value('ruolo');
    expect(array_slice(aocScrittura($s, $incasso, $rataId)['righe'], 2))->toBe([[AccessoriDaIncassare::RUOLO, 'dare', 92, 'Neri', null, false], [$mastro, 'avere', 92, 'Neri', null, false]]);
    $versato = aovViste($this, $s);
    // 0,92 ai condòmini per la voce: 500, 300 e 200 millesimi, 0,46 + 0,276 + 0,184 → 0,46, 0,28 (il resto più grande), 0,18.
    expect(aovDifferenza($prima, $versato))->toBe([
        'riepilogo' => ['costi' => -92, 'risultato' => 92, 'accessori' => 92],
        'situazione' => ['banca' => 92],
        'conguaglio' => ['Bianchi' => [$g => [0 => -28, 3 => -28, 5 => -28]], 'Neri' => [$g => [0 => -46, 3 => -46, 5 => -46]], 'Verdi' => [$g => [0 => -18, 3 => -18, 5 => -18]]],
        'a_di_tutti' => -92,
        'registro' => ['entrate' => 92, 'saldo' => 92, 'righe' => 1],
        'estratti' => ['Neri' => ['addebiti' => 92, 'versato' => 92, 'lista' => 92, 'lista_versato' => 92]],
    ]);

    // Il versamento torna indietro il 12/07: come all'addebito, e nel registro l'incasso e la sua rettifica.
    $this->travelTo('2026-07-12 10:00:00');
    lcdStorna($this, $s, $incasso);
    expect(aovDifferenza($prima, aovViste($this, $s)))->toBe([
        'situazione' => ['crediti' => 92, 'accessori' => 92],
        'conguaglio' => ['Neri' => [$g => [4 => 92, 5 => 92]]],
        'registro' => ['entrate' => 92, 'uscite' => 92, 'righe' => 2],
        'estratti' => ['Neri' => ['addebiti' => 92, 'saldo' => 92, 'lista' => 92]],
    ]);
});

// ─── L'accordo di rientro ──────────────────────────────────────────────────────────────────────────────────────────────

it('l\'accordo di rientro di Neri, € 225,92: il versamento dell\'accordo di € 75,31 va prima agli interessi e al sollecito, che diventano spese di nessuno, poi alla rata 1; il sollecito nell\'accordo non si annulla', function () {
    [$s, $interessi, $sollecito] = aclAccordo($this);
    $prima = aovViste($this, $s);
    $this->travelTo('2026-07-31 10:00:00');
    $qi = (int) aocQuota($interessi)->id;
    $qs = (int) aocQuota($sollecito)->id;
    $rata1 = lcdQuota($s, 'Neri', 1);
    // 75,31 − 0,92 − 25,00 = 49,39 alla rata 1.
    $incasso = aclVersa($this, $s, [$qi => 92, $qs => 2500, $rata1 => 4939], '2026-07-31');
    $coppie = collect(aocScrittura($s, $incasso)['righe'])->filter(fn ($r) => $r[0] !== 'banca' && $r[0] !== 'crediti_condomini')->values()->all();
    $mastroI = DB::table('conti_contabili')->where('id', $s['interessi']->conto_contabile_id)->value('ruolo');
    $mastroP = DB::table('conti_contabili')->where('id', $s['postali']->conto_contabile_id)->value('ruolo');
    expect(collect($coppie)->map(fn ($r) => [$r[0], $r[1], $r[2]])->sort()->values()->all())->toBe(collect([[AccessoriDaIncassare::RUOLO, 'dare', 92], [AccessoriDaIncassare::RUOLO, 'dare', 2500],
        [$mastroI, 'avere', 92], [$mastroP, 'avere', 2500]])->sort()->values()->all());

    $g = $s['g']->id;
    // Le spese di tutti scendono di 0,92 + 25,00 = 25,92: per la voce degli interessi 0,46, 0,28, 0,18; per «Spese postali» 12,50, 7,50, 5,00.
    expect(aovDifferenza($prima, aovViste($this, $s)))->toBe([
        'riepilogo' => ['costi' => -2592, 'risultato' => 2592, 'accessori' => 2592],
        'situazione' => ['crediti' => -7531, 'banca' => 7531, 'accessori' => -2592],
        'conguaglio' => ['Bianchi' => [$g => [0 => -778, 3 => -778, 5 => -778]], 'Neri' => [$g => [0 => -1296, 3 => -1296, 4 => -7531, 5 => -8827]],
            'Verdi' => [$g => [0 => -518, 3 => -518, 5 => -518]]],
        'a_di_tutti' => -2592,
        'registro' => ['entrate' => 7531, 'saldo' => 7531, 'righe' => 1],
        'estratti' => ['Neri' => ['versato' => 7531, 'saldo' => -7531, 'lista_versato' => 2592]],
    ]);
    aocAnnulla($this, $s, $sollecito)->assertStatus(422)
        ->assertJsonPath('errors.addebito.0', sprintf(AddebitiIndividuali::FRASE_IN_ACCORDO, '15/07/2026'));
});

// ─── La successione con gli interessi aperti ───────────────────────────────────────────────────────────────────────────

/** Un erede di Neri, come lo crea l'anagrafica. */
function aovErede(array $s, string $nome): \App\Models\Anagrafica
{
    $p = \App\Models\Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . '-aov-' . $s['c']->id . '@test.it', 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'AOV' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** La successione di U1 dal 01/09/2026 ad Anna e Bruno al 50 %, con l'arretrato agli eredi, registrata dalla pagina il 10/09; gli eredi fra le persone. */
function aovSuccessione($test, array &$s): void
{
    $anna = aovErede($s, 'Anna');
    $bruno = aovErede($s, 'Bruno');
    $test->travelTo('2026-09-10 10:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), [
        'tipo' => 'successione', 'riga_uscente_id' => $s['righe']['Neri'], 'decorrenza' => '2026-09-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $anna->id, 'quota' => 50], ['anagrafica_id' => $bruno->id, 'quota' => 50]], 'arretrato' => 'eredi', 'erede_di_riferimento' => $anna->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta',
    ])->assertSessionHasNoErrors();
    $s['persone'] += ['Anna' => $anna, 'Bruno' => $bruno];
}

it('la successione di Neri con gli interessi aperti e l\'arretrato agli eredi: gli interessi passano ad Anna e Bruno per metà, fuori da B; il conguaglio di nessuno cambia, «Accessori da incassare» resta', function () {
    $senza = accViaVerdi($this);
    aovSuccessione($this, $senza);
    $vSenza = aovViste($this, $senza, null, ['Neri', 'Anna', 'Bruno']);

    $s = accViaVerdi($this);
    $this->travelTo('2026-06-29 10:00:00');
    $rataId = (int) accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertCreated()->json('rate.0');
    // Gli interessi senza il versamento parziale: € 300,00 × 1,60 % × 90/365 = 1,1836 → € 1,18; a metà, € 0,59 a testa.
    expect(DB::table('rate_quote')->where('rata_id', $rataId)->sum('importo'))->toEqual(118);
    aovSuccessione($this, $s);
    $v = aovViste($this, $s, null, ['Neri', 'Anna', 'Bruno']);
    $riga = fn (array $x, string $chi) => array_values($x['conguaglio'][$chi] ?? [[]])[0];
    expect([$v['estratti']['Anna']['saldo'] - $vSenza['estratti']['Anna']['saldo'], $v['estratti']['Bruno']['saldo'] - $vSenza['estratti']['Bruno']['saldo']])->toBe([59, 59])
        ->and($v['situazione']['accessori'])->toBe(118)
        ->and($v['situazione']['crediti'] - $vSenza['situazione']['crediti'])->toBe(118)
        // B e il conguaglio di ognuno come senza gli interessi; la posizione degli eredi 0,59 in più ciascuno, e il loro saldo; l'estratto degli eredi torna.
        ->and(array_slice($riga($v, 'Neri'), 0, 4))->toBe(array_slice($riga($vSenza, 'Neri'), 0, 4))
        ->and(array_slice($riga($v, 'Anna'), 0, 4))->toBe(array_slice($riga($vSenza, 'Anna'), 0, 4))
        ->and(array_slice($riga($v, 'Bruno'), 0, 4))->toBe(array_slice($riga($vSenza, 'Bruno'), 0, 4))
        ->and([$riga($v, 'Anna')[4] - $riga($vSenza, 'Anna')[4], $riga($v, 'Bruno')[4] - $riga($vSenza, 'Bruno')[4]])->toBe([59, 59])
        ->and([$riga($v, 'Anna')[5] - $riga($vSenza, 'Anna')[5], $riga($v, 'Bruno')[5] - $riga($vSenza, 'Bruno')[5]])->toBe([59, 59])
        ->and($v['controlli'])->toBe($vSenza['controlli'])
        ->and(lcdProvaPersone($this, $this->user, $s['c']->id, $s['e']->id, ['Anna' => $s['persone']['Anna']->id, 'Bruno' => $s['persone']['Bruno']->id]))->toBe([]);
});

it('la successione di Neri con gli interessi aperti e l\'arretrato agli eredi: l\'estratto conto di Neri non ha più gli interessi passati agli eredi', function () {
    $senza = accViaVerdi($this);
    aovSuccessione($this, $senza);
    $s = accViaVerdi($this);
    $this->travelTo('2026-06-29 10:00:00');
    accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertCreated();
    aovSuccessione($this, $s);
    $this->travelTo('2027-01-15 10:00:00');

    // € 1,18 a Neri il 29/06 e € 1,18 da Neri agli eredi il 01/09: per Neri zero, come senza gli interessi; per gli eredi € 0,59 ciascuno.
    expect(aocEstratto($this, $s, 'Neri')['saldo'] - aocEstratto($this, $senza, 'Neri')['saldo'])->toBe(0)
        ->and(lcdProvaPersone($this, $this->user, $s['c']->id, $s['e']->id, ['Neri' => $s['persone']['Neri']->id]))->toBe([]);
});

it('gli interessi passati agli eredi non si annullano dall\'estratto conto: la rata è di due eredi e del passaggio, che li ha scritti', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-06-29 10:00:00');
    $rataId = (int) accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertCreated()->json('rate.0');
    aovSuccessione($this, $s);
    $prima = DB::table('rate_quote')->where('rata_id', $rataId)->orderBy('id')->get(['anagrafica_id', 'importo'])->map(fn ($q) => [(int) $q->anagrafica_id, (int) $q->importo])->all();

    // Annullare dalla pagina di Anna toglierebbe anche la quota di Bruno e la scrittura del passaggio: si rifiuta, e le tre quote restano.
    aocAnnulla($this, $s, $rataId)->assertStatus(422);
    expect(DB::table('rate_quote')->where('rata_id', $rataId)->orderBy('id')->get(['anagrafica_id', 'importo'])->map(fn ($q) => [(int) $q->anagrafica_id, (int) $q->importo])->all())->toBe($prima)
        ->and(collect(app(AddebitiIndividuali::class)->dellaPersona((int) $s['c']->id, (int) $s['persone']['Anna']->id))->firstWhere('rata_id', $rataId)['annullabile'])->toBeFalse();
});

it('la successione di Neri con gli interessi aperti: nell\'estratto di Neri la riga «Agli eredi» in avere dice a chi sono andati; € 1,18 contati una volta sola, in ogni vista', function () {
    $senza = accViaVerdi($this);
    aovSuccessione($this, $senza);
    $vSenza = aovViste($this, $senza, null, ['Neri', 'Anna', 'Bruno']);
    $s = accViaVerdi($this);
    $this->travelTo('2026-06-29 10:00:00');
    accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertCreated();
    aovSuccessione($this, $s);
    $v = aovViste($this, $s, null, ['Neri', 'Anna', 'Bruno']);

    // Neri: € 1,18 in dare il 29/06 e € 1,18 in avere il 01/09, con il nome della scrittura e la frase della successione; i totali come senza gli interessi.
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone']['Neri']]))->assertOk()->viewData('page')['props'];
    $agliEredi = collect($pagina['timeline'])->firstWhere('descrizione', 'Agli eredi: Interessi di mora al 29/06/2026');
    expect($agliEredi)->not->toBeNull()
        ->and([$agliEredi['dare'], $agliEredi['avere'], $agliEredi['data']])->toBe([0, 118, '01/09/2026'])
        ->and($agliEredi['dettagli'][0]['text'])->toStartWith('Passato agli eredi con la successione del 01/09/2026: € 0,59 ad Anna, € 0,59 a Bruno')
        ->and(aovDifferenza($vSenza['estratti']['Neri'], $v['estratti']['Neri']))->toBe([])
        // Gli eredi € 0,59 ciascuno: in tutto € 1,18, una volta sola, come i crediti verso i condòmini e «Accessori da incassare».
        ->and(aovDifferenza($vSenza['estratti'], $v['estratti']))->toBe(['Anna' => ['addebiti' => 59, 'saldo' => 59, 'lista' => 59], 'Bruno' => ['addebiti' => 59, 'saldo' => 59, 'lista' => 59]])
        ->and($v['situazione']['crediti'] - $vSenza['situazione']['crediti'])->toBe(118)
        ->and($v['situazione']['accessori'])->toBe(118);
    // La posizione e il saldo del conguaglio di Neri come senza gli interessi; quelli degli eredi € 0,59 in più; i controlli uguali.
    $riga = fn (array $x, string $chi) => array_values($x['conguaglio'][$chi] ?? [[]])[0];
    expect($riga($v, 'Neri'))->toBe($riga($vSenza, 'Neri'))
        ->and([$riga($v, 'Anna')[4] - $riga($vSenza, 'Anna')[4], $riga($v, 'Bruno')[4] - $riga($vSenza, 'Bruno')[4]])->toBe([59, 59])
        ->and($v['controlli'])->toBe($vSenza['controlli'])
        ->and(lcdProvaPersone($this, $this->user, $s['c']->id, $s['e']->id, ['Neri' => $s['persone']['Neri']->id, 'Anna' => $s['persone']['Anna']->id, 'Bruno' => $s['persone']['Bruno']->id]))->toBe([]);
});

it('gli interessi passati agli eredi: l\'elenco degli addebiti di Anna e di Neri dice perché non si annullano, con la data della successione; annullato il passaggio, si annullano', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-06-29 10:00:00');
    $rataId = (int) accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertCreated()->json('rate.0');
    aovSuccessione($this, $s);
    $frase = sprintf(AddebitiIndividuali::FRASE_PASSATO_AGLI_EREDI, '01/09/2026');
    expect($frase)->toBe('Passato agli eredi con la successione del 01/09/2026: per annullarlo, annulla prima il passaggio.');

    $elenco = fn (string $chi) => collect($this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone'][$chi]]))->assertOk()
        ->viewData('page')['props']['addebiti'])->firstWhere('rata_id', $rataId);
    // Al posto di «Annulla» il perché: nell'elenco di Anna (€ 0,59) e in quello di Neri, dove resta il suo versato (€ 0,00).
    expect($elenco('Anna'))->toMatchArray(['importo' => 59, 'annullabile' => false, 'perche_resta' => $frase])
        ->and($elenco('Bruno'))->toMatchArray(['importo' => 59, 'annullabile' => false, 'perche_resta' => $frase])
        ->and($elenco('Neri'))->toMatchArray(['importo' => 0, 'annullabile' => false, 'perche_resta' => $frase]);
    aocAnnulla($this, $s, $rataId)->assertStatus(422)->assertJsonPath('errors.addebito.0', $frase);

    // La strada che la frase dice: annullato il passaggio, gli interessi tornano a Neri e si annullano.
    $passaggio = \App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']['U1']->id)->latest('id')->firstOrFail();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Successione registrata per errore'])
        ->assertRedirect();
    expect($elenco('Neri'))->toMatchArray(['importo' => 118, 'annullabile' => true, 'perche_resta' => null]);
    aocAnnulla($this, $s, $rataId)->assertOk();
    expect(DB::table('rate_quote')->where('rata_id', $rataId)->exists())->toBeFalse();
});
