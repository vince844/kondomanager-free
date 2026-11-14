<?php

/**
 * La scheda «Condòmini» del rendiconto: le posizioni per persona e unità alla data (§ 9) e i crediti per anzianità, una riga per rata non pagata
 * (§ 10). Le due letture vengono dalla lettura comune dei debiti, fatta per gruppi di persone: le stesse cifre di «Rate scadute» alla stessa data.
 * Il § 9 non ha gli anticipi nel totale: stanno in una colonna loro, e una riga li lega al conto «Anticipi»; un'altra lega le posizioni ai crediti
 * della situazione patrimoniale, con le spese anticipate per singole unità. Il § 10 dice le due annate della rata, il pregresso con le sue parole,
 * quanto è stato versato dopo la data (senza una seconda lettura) e l'ultima lettera partita o spedita.
 *
 * Le cifre sono quelle del progetto (§ 4, test 17 e 18), fatte a mano. Centesimi; positivo = debito, negativo = credito.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Gestionale\PosizioniDeiCondomini;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/Beta59313AdPersonamScenari.php';
require_once __DIR__.'/LetturaDebitiProva.php';

beforeEach(function () {
    $this->user = be52Utente();
    // Le pagine si leggono dalla loro rotta: il test non dipende da una compilazione di Vite.
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

function rcdPosizioni(array $s, ?Esercizio $e = null): array
{
    return app(PosizioniDeiCondomini::class)->posizioni($s['c'], $e ?? $s['e']);
}

function rcdCrediti(array $s, ?Esercizio $e = null, string $ordine = 'scaduto'): array
{
    return app(PosizioniDeiCondomini::class)->crediti($s['c'], $e ?? $s['e'], $ordine);
}

/** Le righe del § 9 per nome della persona e dell'unità: [«Bice · Int. C» => [colonna => importo]]. */
function rcdRighe(array $p, array $colonne = ['ordinarie', 'straordinarie', 'precedenti', 'non_emesse', 'crediti', 'totale', 'anticipi']): array
{
    return collect($p['righe'])->mapWithKeys(fn ($r) => [$r['nome'].' · '.($r['unita'] ?? '—') => array_intersect_key($r, array_flip($colonne))])->all();
}

/** Un anticipo scritto come lo scriveva l'incasso senza una quota d'appoggio: DARE banca, AVERE «Anticipi ricevuti» a nome della persona. */
function rcdAnticipo(array $s, string $chi, int $cent, string $data): void
{
    $anticipi = \App\Models\Gestionale\ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'anticipi_condomini')->firstOrFail();
    $sc = \App\Models\Gestionale\ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id,
        'data_registrazione' => $data, 'data_competenza' => $data, 'causale' => 'Anticipo', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata']);
    $sc->righe()->create(['conto_contabile_id' => $s['cassa']->conto_contabile_id, 'cassa_id' => $s['cassa']->id, 'tipo_riga' => 'dare', 'importo' => $cent]);
    $sc->righe()->create(['conto_contabile_id' => $anticipi->id, 'anagrafica_id' => $s['persone'][$chi]->id, 'tipo_riga' => 'avere', 'importo' => $cent, 'note' => 'Anticipo / Eccedenza']);
}

/** Una lettera del registro scritta a mano: il tipo, lo stato, il canale, il giorno. */
function rcdLettera(array $s, string $chi, string $tipo, string $stato, string $giorno, string $canale = 'stampa'): RegistroInvio
{
    return RegistroInvio::create([
        'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone'][$chi]->id, 'tipo' => $tipo, 'canale' => $canale,
        'documentabile_type' => \App\Models\Anagrafica::class, 'documentabile_id' => $s['persone'][$chi]->id,
        'dati' => ['lettera' => (string) \Illuminate\Support\Str::uuid(), 'persona' => ['id' => $s['persone'][$chi]->id, 'nome' => $s['persone'][$chi]->nome], 'righe' => [], 'totale' => 0],
        'stato' => $stato, 'emesso_il' => \Illuminate\Support\Carbon::parse($giorno.' 10:00', 'Europe/Rome'),
    ]);
}

// ─── § 9, le posizioni (test 17) ───────────────────────────────────────────────────────────────────────────────────────

it('§ 9 di Via Roma al 31/12: Bice € 410,00 di quote ordinarie dell\'esercizio; le posizioni sommano ai crediti meno le spese anticipate', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $p = rcdPosizioni($s);

    expect($p['alla'])->toBe('2026-12-31')
        ->and(rcdRighe($p))->toBe(['Bice · Int. C' => ['ordinarie' => 41000, 'straordinarie' => 0, 'precedenti' => 0, 'non_emesse' => 0, 'crediti' => 0, 'totale' => 41000, 'anticipi' => 0]])
        ->and($p['riga_crediti'])->toBe(['crediti' => 41000, 'posizioni' => 41000, 'spese_anticipate' => 0, 'differenza' => 0])
        ->and($p['riga_anticipi'])->toBe(['anticipi' => 0, 'conto' => 0, 'differenza' => 0]);
});

it('§ 9: Ugo paga € 30,00 in più sulla rata 3: la sua riga dice il credito sulla rata, −€ 30,00, e i crediti tornano', function () {
    $s = vrmScenario($this, 3000);
    vrmGiorno($this, '2027-01-15');
    $p = rcdPosizioni($s);

    expect(rcdRighe($p, ['ordinarie', 'crediti', 'totale']))->toBe(['Bice · Int. C' => ['ordinarie' => 41000, 'crediti' => 0, 'totale' => 41000], 'Ugo · Int. A' => ['ordinarie' => 0, 'crediti' => -3000, 'totale' => -3000]])
        ->and($p['totali']['totale'])->toBe(38000)
        ->and($p['riga_crediti'])->toBe(['crediti' => 38000, 'posizioni' => 38000, 'spese_anticipate' => 0, 'differenza' => 0]);
});

it('§ 9 con un anticipo di € 50,00 di Ugo: non è nel totale, sta nella sua colonna; i crediti tornano, e gli anticipi ricevuti tornano con il conto', function () {
    $s = vrmScenario($this);
    rcdAnticipo($s, 'ugo', 5000, '2026-12-20');
    vrmGiorno($this, '2027-01-15');
    $p = rcdPosizioni($s);

    expect(rcdRighe($p, ['totale', 'anticipi']))->toBe(['Bice · Int. C' => ['totale' => 41000, 'anticipi' => 0], 'Ugo · —' => ['totale' => 0, 'anticipi' => -5000]])
        ->and($p['riga_crediti'])->toBe(['crediti' => 41000, 'posizioni' => 41000, 'spese_anticipate' => 0, 'differenza' => 0])
        ->and($p['riga_anticipi'])->toBe(['anticipi' => 5000, 'conto' => 5000, 'differenza' => 0]);
});

it('§ 9 con la fattura ad personam: prima dell\'emissione nessuna posizione e € 1.220,00 di spese anticipate; dopo, Mario Rossi € 1.220,00 di quote straordinarie', function () {
    $s = b59apBase();
    $ft = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $this->travelTo('2026-03-20 10:00:00');
    $prima = app(PosizioniDeiCondomini::class)->posizioni($s['c'], $s['e']);
    expect($prima['righe'])->toBe([])
        ->and($prima['riga_crediti'])->toBe(['crediti' => 122000, 'posizioni' => 0, 'spese_anticipate' => 122000, 'differenza' => 0]);

    $piano = b59apPiano($this, $s, $ft);
    b59apEmetti($this, $s, $piano, [1, 2]);
    $this->travelTo('2026-04-05 10:00:00');
    $dopo = app(PosizioniDeiCondomini::class)->posizioni($s['c'], $s['e']);
    expect(rcdRighe($dopo, ['ordinarie', 'straordinarie', 'totale']))->toBe(['Mario Rossi · Int. 1' => ['ordinarie' => 0, 'straordinarie' => 122000, 'totale' => 122000]])
        ->and($dopo['riga_crediti'])->toBe(['crediti' => 122000, 'posizioni' => 122000, 'spese_anticipate' => 0, 'differenza' => 0]);
});

it('§ 9: il pregresso e le rate del piano dell\'anno prima stanno negli esercizi precedenti; nell\'anno del piano le rate sono dell\'esercizio', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 2, 'rata_zero', [['ugo', 'A', 30000]], 24000);
    lcdEmetti($this, $s, [0, 1, 2], '2026-01-02');
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e2027, $s['g']->id);
    $this->travelTo('2027-03-01 10:00:00');

    expect(rcdRighe(rcdPosizioni($s), ['ordinarie', 'precedenti', 'totale']))->toBe(['Ugo · Int. A' => ['ordinarie' => 24000, 'precedenti' => 30000, 'totale' => 54000]])
        ->and(rcdRighe(rcdPosizioni($s, $e2027), ['ordinarie', 'precedenti', 'totale']))->toBe(['Ugo · Int. A' => ['ordinarie' => 0, 'precedenti' => 54000, 'totale' => 54000]]);
});

it('§ 9 con 3.000 unità × 12 rate: la somma per persona e unità resta piccola, e una lettura per rata di tutto il condominio no', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $unita = [];
    foreach (range(1, 3000) as $i) {
        $unita["U{$i}"] = ["persona{$i}", 1];
    }
    $s = lcdScenario($unita, 12, 'prima_rata', [], 1200 * 3000 * 100);
    lcdEmetti($this, $s, range(1, 12), '2026-01-02');
    $this->travelTo('2027-01-20 10:00:00');
    $servizio = app(PosizioniDeiCondomini::class);
    gc_collect_cycles();
    $base = memory_get_usage();
    memory_reset_peak_usage();
    $p = $servizio->posizioni($s['c'], $s['e']);
    $picco = (memory_get_peak_usage() - $base) / 1048576;

    // Misurato in questo processo: la somma a gruppi di persone circa 10 MB; la lettura per rata di tutto il condominio, da sola, oltre 70.
    expect(count($p['righe']))->toBe(3000)
        ->and($p['totali']['totale'])->toBe(3000 * 120000)
        ->and($p['riga_crediti']['differenza'])->toBe(0)
        ->and($picco)->toBeLessThan(25.0);
});

it('§ 10 con 1.000 persone × 12 rate scadute, lettura compresa: 12.000 righe, sotto i 45 MB oltre la base', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $unita = [];
    foreach (range(1, 1000) as $i) {
        $unita["U{$i}"] = ["persona{$i}", 1];
    }
    $s = lcdScenario($unita, 12, 'prima_rata', [], 1200 * 1000 * 100);
    lcdEmetti($this, $s, range(1, 12), '2026-01-02');
    $this->travelTo('2027-01-20 10:00:00');
    $servizio = app(PosizioniDeiCondomini::class);
    gc_collect_cycles();
    $base = memory_get_usage();
    memory_reset_peak_usage();
    $c = $servizio->crediti($s['c'], $s['e']);
    $picco = (memory_get_peak_usage() - $base) / 1048576;

    // Misurato in questo processo: circa 37 MB, di cui 17 le righe. Con una richiesta vera alla scheda, con 128M, 96,5 MB (la misura è nel controller).
    expect($c['totali']['righe'])->toBe(12000)
        ->and($c['totali']['scaduto'])->toBe(1000 * 120000)
        ->and($picco)->toBeLessThan(45.0);
});

// ─── § 10, i crediti per anzianità (test 18) ───────────────────────────────────────────────────────────────────────────

it('§ 10 di Via Roma al 31/12: le tre rate di Bice, € 410,00 scaduti, gli stessi di «Rate scadute» al 31/12, con i giorni e le fasce', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $c = rcdCrediti($s);
    $rate = collect($c['righe'])->map(fn ($r) => [$r['numero_rata'], $r['importo'], $r['scadenza'], $r['giorni'], $r['fascia'], $r['annata_scadenza'], $r['annata_competenza']])->all();
    $rateScadute = $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id, 'alla' => '2026-12-31']))->viewData('page')['props']['totale'];

    // 05/10 → 31/12: 87 giorni; 05/11: 56; 05/12: 26.
    expect($c['alla'])->toBe('2026-12-31')
        ->and($rate)->toBe([[10, 6750, '2026-10-05', 87, '31_90', '2026', '2026'], [11, 17125, '2026-11-05', 56, '31_90', '2026', '2026'], [12, 17125, '2026-12-05', 26, '1_30', '2026', '2026']])
        ->and($c['totali']['importo'])->toBe(41000)
        ->and($c['totali']['scaduto'])->toBe(41000)
        ->and($c['totali']['scaduto'])->toBe($rateScadute)
        ->and($c['totali']['fasce'])->toBe(['1_30' => 17125, '31_90' => 23875, 'oltre_90' => 0])
        ->and(collect($c['righe'])->pluck('nome')->unique()->values()->all())->toBe(['Bice']);
});

it('§ 10: € 300,00 versati il 20/01/2027 danno «versato dopo» € 300,00 e «resta oggi» € 110,00, senza una seconda lettura dei debiti a oggi', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-20');
    $bice = vrmQuote($s, 'bice');
    vrmIncassa($this, $s, 'bice', [$bice[10] => 6750, $bice[11] => 17125, $bice[12] => 6125], '2027-01-20');
    vrmGiorno($this, '2027-02-15');
    // Le letture dei debiti: la query delle righe sommate sui due mastri della persona, con il giorno dopo la data della lettura.
    $date = [];
    DB::listen(function ($q) use (&$date) {
        if (str_contains($q->sql, '"cc"."ruolo" in (?, ?)') && str_contains($q->sql, 'having')) {
            $date[] = collect($q->bindings)->first(fn ($b) => is_string($b) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $b));
        }
    });
    $c = rcdCrediti($s);

    expect(collect($c['righe'])->map(fn ($r) => [$r['numero_rata'], $r['importo'], $r['versato_dopo'], $r['resta_oggi']])->all())
        ->toBe([[10, 6750, 6750, 0], [11, 17125, 17125, 0], [12, 17125, 6125, 11000]])
        ->and($c['totali']['versato_dopo'])->toBe(30000)
        ->and($c['totali']['resta_oggi'])->toBe(11000)
        ->and($date)->not->toBe([])
        ->and(array_unique($date))->toBe(['2027-01-01']);
});

it('§ 10: un versamento dopo la data poi stornato non è «versato dopo»; uno con la data del 31/12 registrato a gennaio è già nella lettura alla data', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-20');
    $bice = vrmQuote($s, 'bice');
    $tornato = vrmIncassa($this, $s, 'bice', [$bice[10] => 6750], '2027-01-20');
    lcdStorna($this, $s, $tornato);
    vrmIncassa($this, $s, 'bice', [$bice[12] => 5000], '2026-12-31');
    vrmGiorno($this, '2027-02-15');
    $c = rcdCrediti($s);

    // Il versamento del 31/12 (€ 50,00 sulla rata 12) è nella lettura alla data: la rata 12 è € 121,25, e niente è «versato dopo».
    expect(collect($c['righe'])->map(fn ($r) => [$r['numero_rata'], $r['importo'], $r['versato_dopo'], $r['resta_oggi']])->all())
        ->toBe([[10, 6750, 0, 6750], [11, 17125, 0, 17125], [12, 12125, 0, 12125]])
        ->and($c['totali']['versato_dopo'])->toBe(0);
});

it('§ 10 di un esercizio aperto: nessun «versato dopo», la data è oggi; alle 00:30 di Roma del 06/12 la rata del 05/12 è scaduta da un giorno', function () {
    $this->travelTo('2026-11-01 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 3, 'prima_rata', [], 30000, '2026-11-05');
    lcdEmetti($this, $s, [1, 2, 3], '2026-11-01');
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-12-06 00:30', 'Europe/Rome'));
    $c = rcdCrediti($s);

    expect($c['alla'])->toBe('2026-12-06')->and($c['finito'])->toBeFalse()
        ->and(collect($c['righe'])->map(fn ($r) => [$r['numero_rata'], $r['scaduta'], $r['giorni'], $r['fascia'], $r['versato_dopo']])->all())
        ->toBe([[1, true, 31, '31_90', null], [2, true, 1, '1_30', null], [3, false, 0, null, null]])
        ->and($c['totali']['scaduto'])->toBe(20000)
        ->and($c['totali']['importo'])->toBe(30000);
});

it('§ 10: la rata del 05/01/2027 di un piano del 2026 ha l\'annata della scadenza 2027 e quella della competenza 2026', function () {
    $this->travelTo('2026-11-01 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 3, 'prima_rata', [], 30000, '2026-11-05');
    lcdEmetti($this, $s, [1, 2, 3], '2026-11-01');
    $this->travelTo('2027-02-10 10:00:00');
    // Senza l'esercizio del 2027 l'annata della scadenza non c'è, e la cella lo dice.
    expect(collect(rcdCrediti($s)['righe'])->firstWhere('numero_rata', 3)['annata_scadenza'])->toBe('nessun esercizio la contiene');
    Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $c = rcdCrediti($s);

    expect(collect($c['righe'])->map(fn ($r) => [$r['numero_rata'], $r['scadenza'], $r['scaduta'], $r['annata_scadenza'], $r['annata_competenza']])->all())
        ->toBe([[1, '2026-11-05', true, '2026', '2026'], [2, '2026-12-05', true, '2026', '2026'], [3, '2027-01-05', false, '2027', '2026']]);
});

it('§ 10: un\'annata che non si può dire lo dice: la scadenza prima del primo esercizio, il piano straordinario senza la data della delibera', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 2, 'prima_rata', [], 24000, '2025-12-05');
    lcdEmetti($this, $s, [1, 2], '2026-01-02');
    DB::table('gestioni')->where('id', $s['g']->id)->update(['tipo' => 'straordinaria']);
    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['data_delibera_assemblea' => null]);
    $this->travelTo('2026-03-01 10:00:00');
    $prima = collect(rcdCrediti($s)['righe'])->firstWhere('numero_rata', 1);

    expect([$prima['scadenza'], $prima['annata_scadenza'], $prima['annata_competenza']])->toBe(['2025-12-05', 'prima del primo esercizio', 'senza la data della delibera']);
});

it('§ 10: il pregresso senza origine dice «origine non dichiarata», non un numero di giorni, e sta oltre i 90 giorni', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 2, 'rata_zero', [['ugo', 'A', 30000]], 24000);
    lcdEmetti($this, $s, [0, 1, 2], '2026-01-02');
    $this->travelTo('2027-01-10 10:00:00');
    $zero = collect(rcdCrediti($s)['righe'])->firstWhere('numero_rata', 0);

    expect($zero)->toMatchArray(['importo' => 30000, 'giorni' => null, 'fascia' => 'oltre_90', 'origine' => 'origine non dichiarata', 'annata_scadenza' => 'origine non dichiarata', 'annata_competenza' => 'origine non dichiarata']);
});

it('§ 10: «ultima lettera partita o spedita» dice il sollecito per email, non la diffida stampata e non spedita', function () {
    $s = vrmScenario($this);
    rcdLettera($s, 'bice', 'sollecito', RegistroInvio::STATO_INVIATA, '2026-12-10', RegistroInvio::CANALE_EMAIL);
    rcdLettera($s, 'bice', 'diffida', RegistroInvio::STATO_STAMPATA, '2026-12-20');
    vrmGiorno($this, '2027-01-15');
    $c = rcdCrediti($s);

    expect(collect($c['righe'])->pluck('lettera')->unique()->values()->all())->toBe([['tipo' => 'sollecito', 'data' => '2026-12-10']]);
});

it('§ 10 in ordine di scaduto o di persona: chi deve di più prima, o in ordine alfabetico; le rate di ognuno per scadenza', function () {
    $s = vrmScenario($this);
    // Ugo non ha pagato le rate dalla 9 alla 12: i suoi € 685,00 stanno sopra i € 410,00 di Bice.
    vrmGiorno($this, '2026-12-20');
    lcdStorna($this, $s, $s['incasso_ugo']);
    vrmIncassa($this, $s, 'ugo', array_fill_keys(array_slice(vrmQuote($s, 'ugo'), 0, 8), 17125), '2026-12-20');
    vrmGiorno($this, '2027-01-15');

    $perScaduto = collect(rcdCrediti($s)['righe'])->map(fn ($r) => $r['nome'].' '.$r['numero_rata'])->all();
    $perPersona = collect(rcdCrediti($s, null, 'persona')['righe'])->map(fn ($r) => $r['nome'].' '.$r['numero_rata'])->all();

    expect($perScaduto)->toBe(['Ugo 9', 'Ugo 10', 'Ugo 11', 'Ugo 12', 'Bice 10', 'Bice 11', 'Bice 12'])
        ->and($perPersona)->toBe(['Bice 10', 'Bice 11', 'Bice 12', 'Ugo 9', 'Ugo 10', 'Ugo 11', 'Ugo 12']);
});

it('§ 9 e § 10 con il pregresso spalmato nella prima rata (il metodo di serie): le cifre restano nelle quote dell\'esercizio, e la riga e la frase lo dicono', function () {
    // Rate da € 120,00 × 2, il debito del 2025 di € 300,00 dentro la prima rata (€ 420,00): l'importo non si divide fra le colonne (quale parte paga per
    // prima un versamento è una regola che il programma non ha), e la riga porta il segno.
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 2, 'prima_rata', [['ugo', 'A', 30000]], 24000);
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    DB::table('saldi')->where('condominio_id', $s['c']->id)->update(['origine_dichiarata' => 'esercizio', 'esercizio_origine_id' => $e2025->id]);
    lcdEmetti($this, $s, [1, 2], '2026-01-02');
    $this->travelTo('2027-03-01 10:00:00');
    $p = rcdPosizioni($s);
    $c = rcdCrediti($s);
    $r = $p['righe'][0];

    expect([$r['ordinarie'], $r['precedenti'], $r['totale'], $r['pregresso_nella_rata']])->toBe([54000, 0, 54000, true])
        // Il pregresso spalmato può essere un debito o un credito di prima: la frase dice tutti e due.
        ->and($p['avvertenze']['esercizio_della_rata'])->toContain('Con il pregresso spalmato nelle rate')
        ->toContain('il debito o il credito di prima sta nelle quote dell\'esercizio (le aumenta o le riduce)')
        ->and(collect($c['righe'])->map(fn ($x) => [$x['numero_rata'], $x['importo'], $x['pregresso_nella_rata']])->all())->toBe([[1, 42000, true], [2, 12000, false]]);
    $htmlNove = view('pdf.gestionale.rendiconto_posizioni', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'posizioni' => $p])->render();
    $htmlDieci = view('pdf.gestionale.rendiconto_crediti', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'crediti' => $c])->render();
    // Le stesse parole di «Rate scadute»: la riga del § 10 è una rata; quella del § 9 raccoglie le rate della persona e dell'unità.
    expect($htmlNove)->toContain('contiene anche una parte di pregresso')->toContain('Con il pregresso spalmato nelle rate')->not->toContain('comprende pregresso')
        ->and(substr_count($htmlDieci, e(\App\Services\Gestionale\PosizioniDeiCondomini::NOTA_PREGRESSO_NELLA_RATA)))->toBe(1)
        ->and($htmlDieci)->not->toContain('comprende pregresso');

    // Senza il pregresso nella rata (la rata 0) la riga non ha il segno.
    $this->travelTo('2026-01-02 10:00:00');
    $zero = lcdScenario(['A' => ['ugo', 1000]], 2, 'rata_zero', [['ugo', 'A', 30000]], 24000);
    lcdEmetti($this, $zero, [0, 1, 2], '2026-01-02');
    $this->travelTo('2027-03-01 10:00:00');
    expect(rcdPosizioni($zero)['righe'][0]['pregresso_nella_rata'])->toBeFalse();
});

it('§ 10: un versamento dopo la data oltre il residuo lascia «resta oggi» a zero; uno con la data dopo oggi non è ancora «versato dopo»; la frase dice il numero negativo', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-20');
    $bice = vrmQuote($s, 'bice');
    // € 50,00 in più sulla rata 12, che restano sulla rata come credito.
    vrmIncassa($this, $s, 'bice', [$bice[12] => 17125], '2027-01-20', 5000);
    // Una riga con la data dopo oggi: le rotte dell'incasso la rifiutano («antecedente o uguale» a oggi), il giornale la può avere da un'importazione.
    $futuro = vrmIncassa($this, $s, 'bice', [$bice[11] => 10000], '2027-01-20');
    DB::table('scritture_contabili')->where('id', $futuro)->update(['data_competenza' => '2027-03-01']);
    vrmGiorno($this, '2027-02-15');
    $c = rcdCrediti($s);

    expect(collect($c['righe'])->map(fn ($r) => [$r['numero_rata'], $r['versato_dopo'], $r['resta_oggi']])->all())->toBe([[10, 0, 6750], [11, 0, 17125], [12, 22125, 0]])
        ->and($c['avvertenze']['versato_dopo'])->toContain('un numero negativo è un versamento di prima tornato indietro dopo la data');
});

it('§ 10: l\'ultima lettera è quella fino a oggi, anche partita dopo la data; § 9: la frase dice le righe che ci sono', function () {
    $s = vrmScenario($this);
    rcdLettera($s, 'bice', 'sollecito', RegistroInvio::STATO_INVIATA, '2026-12-10', RegistroInvio::CANALE_EMAIL);
    rcdLettera($s, 'bice', 'diffida', RegistroInvio::STATO_INVIATA, '2027-01-12', RegistroInvio::CANALE_EMAIL);
    vrmGiorno($this, '2027-01-15');

    expect(collect(rcdCrediti($s)['righe'])->pluck('lettera')->unique()->values()->all())->toBe([['tipo' => 'diffida', 'data' => '2027-01-12']])
        ->and(rcdPosizioni($s)['avvertenze']['ordine'])->toContain('Sono elencate le righe con almeno un importo diverso da zero')->not->toContain('posizione a zero');
});

it('§ 9: la rata di un piano dell\'esercizio dopo, emessa entro la data, sta fra gli esercizi successivi, a video e in stampa', function () {
    $this->travelTo('2026-12-01 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 1, 'prima_rata', [], 12000, '2026-12-05');
    lcdEmetti($this, $s, [1], '2026-12-01');
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $piano = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $e2027->id, 'nome' => 'Preventivo 2027',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2027-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $e2027);
    $this->travelTo('2026-12-20 10:00:00');
    lcdEmetti($this, ['piano' => $piano] + $s, [1], '2026-12-20');
    $this->travelTo('2027-01-15 10:00:00');
    $p = rcdPosizioni($s);

    expect(rcdRighe($p, ['ordinarie', 'precedenti', 'successivi', 'totale']))->toBe(['Ugo · Int. A' => ['ordinarie' => 12000, 'precedenti' => 0, 'successivi' => 12000, 'totale' => 24000]])
        ->and($p['mostra']['successivi'])->toBeTrue()
        ->and(view('pdf.gestionale.rendiconto_posizioni', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'posizioni' => $p])->render())->toContain('Esercizi successivi');
});

it('la stampa del § 10 segue l\'ordine della pagina', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    lcdStorna($this, $s, $s['incasso_ugo']);
    vrmIncassa($this, $s, 'ugo', array_fill_keys(array_slice(vrmQuote($s, 'ugo'), 0, 8), 17125), '2026-12-20');
    vrmGiorno($this, '2027-01-15');
    $ricevute = [];
    \Illuminate\Support\Facades\View::composer('pdf.gestionale.rendiconto_crediti', function ($v) use (&$ricevute) {
        $ricevute[] = collect($v->getData()['crediti']['righe'])->map(fn ($r) => $r['nome'].' '.$r['numero_rata'])->all();
    });
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini.crediti.stampa', [$s['c'], $s['e'], 'ordine' => 'persona']))->assertOk();
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini.crediti.stampa', [$s['c'], $s['e']]))->assertOk();

    expect($ricevute)->toBe([['Bice 10', 'Bice 11', 'Bice 12', 'Ugo 9', 'Ugo 10', 'Ugo 11', 'Ugo 12'], ['Ugo 9', 'Ugo 10', 'Ugo 11', 'Ugo 12', 'Bice 10', 'Bice 11', 'Bice 12']]);
});

it('i crediti per anzianità oltre le righe che la pagina regge: una frase invece dell\'errore, e le posizioni si aprono lo stesso', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $limite = new ReflectionProperty(\App\Http\Controllers\Gestionale\Movimenti\RendicontoCondominiController::class, 'limiteMemoria');
    $limite->setValue(null, '64M');
    try {
        $crediti = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini', [$s['c'], $s['e'], 'elenco' => 'crediti']))->assertOk()->viewData('page')['props'];
        $posizioni = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];
    } finally {
        $limite->setValue(null, null);
    }

    expect($crediti['crediti'])->toBeNull()
        ->and($crediti['troppe'])->toContain('più di quante il server riesce a leggere')
        ->and($posizioni['posizioni']['righe'])->toHaveCount(1)
        ->and($posizioni['troppe'])->toBeNull();
});

// ─── La pagina e le stampe ─────────────────────────────────────────────────────────────────────────────────────────────

it('la scheda «Condòmini» dalla rotta, dietro il pannello e nel condominio giusto: i dati sono quelli del servizio; mai nel portale', function () {
    $s = vrmScenario($this, 3000);
    vrmGiorno($this, '2027-01-15');
    // Due elenchi, due richieste: le posizioni di serie, i crediti per anzianità con `?elenco=crediti`. Ogni richiesta legge solo il suo.
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];
    $propsC = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini', [$s['c'], $s['e'], 'elenco' => 'crediti']))->assertOk()->viewData('page')['props'];
    $p = rcdPosizioni($s);
    $c = rcdCrediti($s);

    expect($props['elenco'])->toBe('posizioni')->and($props['crediti'])->toBeNull()
        ->and($props['posizioni']['righe'])->toEqual($p['righe'])
        ->and(array_diff_key($props['posizioni'], ['righe' => 1, 'pagine' => 1]))->toEqual(array_diff_key($p, ['righe' => 1]))
        ->and($propsC['elenco'])->toBe('crediti')->and($propsC['posizioni'])->toBeNull()
        ->and($propsC['crediti']['righe'])->toEqual($c['righe'])
        ->and(array_diff_key($propsC['crediti'], ['righe' => 1, 'pagine' => 1]))->toEqual(array_diff_key($c, ['righe' => 1]));

    $altro = be52Condominio();
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini', [$altro['c'], $s['e']]))->assertNotFound();
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini.posizioni.stampa', [$altro['c'], $s['e']]))->assertNotFound();
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini.crediti.stampa', [$altro['c'], $s['e']]))->assertNotFound();
    $fuori = \App\Models\User::factory()->create();
    $this->actingAs($fuori)->get(route('admin.gestionale.esercizi.rendiconto.condomini', [$s['c'], $s['e']]))->assertForbidden();
    $this->actingAs($fuori)->get(route('admin.gestionale.esercizi.rendiconto.condomini.crediti.stampa', [$s['c'], $s['e']]))->assertForbidden();
    expect(collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'rendiconto/condomini'))
        ->map(fn ($r) => $r->uri())->values()->all())->not->toBe([])->each->toStartWith('admin/gestionale/{condominio}/');
});

it('la scheda a pagine: dieci righe per pagina, la seconda pagina dei crediti con le ultime due; i totali sono di tutte le righe', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [], 120000);
    lcdEmetti($this, $s, range(1, 12), '2026-01-02');
    $this->travelTo('2027-01-20 10:00:00');
    $c = rcdCrediti($s);
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini', [$s['c'], $s['e'], 'elenco' => 'crediti', 'per_page' => 10, 'pagina_crediti' => 2]))->viewData('page')['props'];

    expect($pagina['crediti']['righe'])->toEqual(array_slice($c['righe'], 10))
        ->and(collect($pagina['crediti']['righe'])->pluck('numero_rata')->all())->toBe([11, 12])
        ->and($pagina['crediti']['pagine'])->toMatchArray(['current_page' => 2, 'last_page' => 2, 'total' => 12, 'nome' => 'pagina_crediti'])
        ->and($pagina['crediti']['totali'])->toEqual($c['totali'])
        ->and($pagina['posizioni'])->toBeNull();
});

it('le stampe del § 9 e del § 10 si aprono, dicono le convenzioni a chi ha il foglio in mano, e portano le cifre', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $nove = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini.posizioni.stampa', [$s['c'], $s['e']]))->assertOk();
    $dieci = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini.crediti.stampa', [$s['c'], $s['e']]))->assertOk();
    $htmlNove = view('pdf.gestionale.rendiconto_posizioni', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'posizioni' => rcdPosizioni($s)])->render();
    $htmlDieci = view('pdf.gestionale.rendiconto_crediti', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'crediti' => rcdCrediti($s)])->render();

    expect(substr((string) $nove->getContent(), 0, 4))->toBe('%PDF')->and(substr((string) $dieci->getContent(), 0, 4))->toBe('%PDF')
        ->and($nove->headers->get('Content-Disposition'))->toContain('posizioni-condomini-')
        ->and($dieci->headers->get('Content-Disposition'))->toContain('crediti-per-anzianita-')
        ->and(strlen((string) $nove->getContent()))->toBeGreaterThan(20000)->toBeLessThan(400000)
        ->and(strlen((string) $dieci->getContent()))->toBeGreaterThan(20000)->toBeLessThan(400000)
        ->and($htmlNove)->toContain('positivo vuol dire che devono, negativo che hanno un credito')->toContain('alla data del 31/12/2026')->toContain('€ 410,00')
        ->toContain('Bice')->toContain('senza gli anticipi')
        ->and($htmlDieci)->toContain('positivo vuol dire che devono')->toContain('alla data del 31/12/2026')->toContain('€ 410,00')
        ->toContain('In ordine di scaduto')->toContain('Ultima lettera spedita')->toContain('l\'ultima lettera di sollecito spedita')->toContain('Non va esposto in bacheca');
});

it('gli indirizzi scritti a mano non danno un errore del server: una pagina enorme è la pagina vuota dopo l\'ultima, un ordine che non è una parola è quello di serie, anche nella stampa del § 10', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [], 120000);
    lcdEmetti($this, $s, range(1, 12), '2026-01-02');
    $this->travelTo('2027-01-20 10:00:00');
    $base = route('admin.gestionale.esercizi.rendiconto.condomini', [$s['c'], $s['e']]);
    $enorme = '9223372036854775807';

    $posizioni = $this->actingAs($this->user)->get($base.'?pagina_posizioni='.$enorme)->assertOk()->viewData('page')['props'];
    $crediti = $this->actingAs($this->user)->get($base.'?elenco=crediti&per_page=10&pagina_crediti='.$enorme)->assertOk()->viewData('page')['props'];
    $ordine = $this->actingAs($this->user)->get($base.'?elenco=crediti&ordine[]=persona')->assertOk()->viewData('page')['props'];
    $stampa = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini.crediti.stampa', [$s['c'], $s['e']]).'?ordine[]=persona')->assertOk();

    expect($posizioni['posizioni']['pagine']['current_page'])->toBe($posizioni['posizioni']['pagine']['last_page'] + 1)->and($posizioni['posizioni']['righe'])->toBe([])
        ->and($crediti['crediti']['pagine'])->toMatchArray(['current_page' => 3, 'last_page' => 2])->and($crediti['crediti']['righe'])->toBe([])
        ->and($ordine['crediti']['ordine'])->toBe('scaduto')
        ->and(substr((string) $stampa->getContent(), 0, 4))->toBe('%PDF');
});
