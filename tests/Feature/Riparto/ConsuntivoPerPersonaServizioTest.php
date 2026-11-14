<?php

/**
 * Il consuntivo per persona letto dal giornale (`ConsuntivoPerPersona::perEsercizio`): le cifre per voce e per fattura, i capitoli dei piani
 * dell'esercizio, la delibera. Il caso è Via Verdi (`ConsuntivoPerPersonaScenario.php`); ogni cifra attesa è fatta a mano nel commento.
 */

use App\Models\Esercizio;
use App\Services\Gestionale\ConsuntivoPerPersona;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__ . '/ConsuntivoPerPersonaScenario.php';

/** La spesa di una persona su un conto nella lettura del servizio, sommata sulle sue unità. */
function cpsDi(array $lettura, array $s, string $persona, int $contoId): int
{
    $totale = 0;
    foreach ($lettura['persone'][$s['gestione']->id][$s['persone'][$persona]->id] ?? [] as $unita) {
        $totale += $unita['voci'][$contoId] ?? 0;
    }

    return $totale;
}

/** Per ogni chiave delle uscite e delle persone: la somma delle persone più le non attribuite fa le uscite. */
function cpsQuadra(array $lettura, int $gestioneId): void
{
    $persone = [];
    foreach ($lettura['persone'][$gestioneId] ?? [] as $perUnita) {
        foreach ($perUnita as $unita) {
            foreach ($unita['voci'] as $contoId => $centesimi) {
                $persone[$contoId] = ($persone[$contoId] ?? 0) + $centesimi;
            }
        }
    }
    $g = $lettura['gestioni'][$gestioneId];
    $chiavi = array_unique(array_merge(array_keys($g['uscite']), array_keys($persone), array_keys($g['non_attribuite'])));
    foreach ($chiavi as $contoId) {
        expect(($persone[$contoId] ?? 0) + ($g['non_attribuite'][$contoId] ?? 0))->toBe($g['uscite'][$contoId] ?? 0);
    }
}

it('nella straordinaria la nota di credito senza competenza va con la fattura che rettifica', function () {
    $s = cpmViaVerdi('straordinaria');
    $giornale = cpmGiornale($s);
    $facciata = cpmVoce($s, 'Facciata', [[$s['generali'], 100]]);
    cpmPiano($s, [$facciata->id => null], '2026-02-01');
    $fattura = cpmSpesa($s, $giornale, $facciata, 100000, ['competenza_dal' => '2026-09-15', 'competenza_al' => '2026-09-15']);
    cpmSpesa($s, $giornale, $facciata, -20000, ['tipo' => 'nota_credito', 'rettifica' => $fattura]);

    $lettura = (new ConsuntivoPerPersona())->perEsercizio($s['esercizio']);

    // € 1.000,00 − € 200,00 = € 800,00, tutti con la competenza del 15/09: U2 è di Rossi, 240,00; Bianchi niente; Neri 400,00, Verdi 160,00.
    expect([cpsDi($lettura, $s, 'Neri', $facciata->id), cpsDi($lettura, $s, 'Bianchi', $facciata->id), cpsDi($lettura, $s, 'Rossi', $facciata->id), cpsDi($lettura, $s, 'Verdi', $facciata->id)])
        ->toBe([40000, 0, 24000, 16000])
        ->and($lettura['gestioni'][$s['gestione']->id]['non_calcolabile'])->toBeNull();
    cpsQuadra($lettura, $s['gestione']->id);
});

it('senza la delibera la sola nota di credito di una fattura datata non ferma la gestione', function () {
    $s = cpmViaVerdi('straordinaria');
    $giornale = cpmGiornale($s);
    $facciata = cpmVoce($s, 'Facciata', [[$s['generali'], 100]]);
    cpmPiano($s, [$facciata->id => null]);
    $fattura = cpmSpesa($s, $giornale, $facciata, 100000, ['competenza_dal' => '2026-09-15', 'competenza_al' => '2026-09-15']);
    cpmSpesa($s, $giornale, $facciata, -20000, ['tipo' => 'nota_credito', 'rettifica' => $fattura]);

    $lettura = (new ConsuntivoPerPersona())->perEsercizio($s['esercizio']);

    expect($lettura['gestioni'][$s['gestione']->id]['non_calcolabile'])->toBeNull()
        ->and(cpsDi($lettura, $s, 'Rossi', $facciata->id))->toBe(24000)
        ->and(cpsDi($lettura, $s, 'Bianchi', $facciata->id))->toBe(0);
});

it('la nota di credito collegata va con la fattura che rettifica anche quando ha una sua competenza, come nel preventivo', function () {
    $s = cpmViaVerdi('straordinaria');
    $giornale = cpmGiornale($s);
    $facciata = cpmVoce($s, 'Facciata', [[$s['generali'], 100]]);
    cpmPiano($s, [$facciata->id => null], '2026-02-01');
    $fattura = cpmSpesa($s, $giornale, $facciata, 100000, ['competenza_dal' => '2026-09-15', 'competenza_al' => '2026-09-15']);
    cpmSpesa($s, $giornale, $facciata, -20000, ['tipo' => 'nota_credito', 'rettifica' => $fattura, 'competenza_dal' => '2026-03-15', 'competenza_al' => '2026-03-15']);

    $lettura = (new ConsuntivoPerPersona())->perEsercizio($s['esercizio']);

    // La nota scritta al 15/03 segue la fattura del 15/09: € 800,00 al 15/09, U2 a Rossi 240,00, Bianchi niente; Neri 400,00, Verdi 160,00.
    expect([cpsDi($lettura, $s, 'Neri', $facciata->id), cpsDi($lettura, $s, 'Bianchi', $facciata->id), cpsDi($lettura, $s, 'Rossi', $facciata->id), cpsDi($lettura, $s, 'Verdi', $facciata->id)])
        ->toBe([40000, 0, 24000, 16000]);
});

it('lo speso per fattura, sommato per voce, è lo speso per voce, anche con la nota di credito', function () {
    $s = cpmViaVerdi('straordinaria');
    $giornale = cpmGiornale($s);
    $facciata = cpmVoce($s, 'Facciata', [[$s['generali'], 100]]);
    $tetto = cpmVoce($s, 'Tetto', [[$s['generali'], 100]]);
    $fattura = cpmSpesa($s, $giornale, $facciata, 100000, ['competenza_dal' => '2026-09-15', 'competenza_al' => '2026-09-15']);
    cpmSpesa($s, $giornale, $facciata, -20000, ['tipo' => 'nota_credito', 'rettifica' => $fattura]);
    cpmSpesa($s, $giornale, $tetto, 33333);
    $spese = new SpesaPerVoceService();

    $perVoce = [];
    foreach ($spese->perEsercizioEFattura($s['esercizio'], [$facciata->id, $tetto->id]) as $riga) {
        $perVoce[$riga['conto_id']] = ($perVoce[$riga['conto_id']] ?? 0) + $riga['importo'];
    }

    expect($perVoce)->toEqual($spese->perEsercizio($s['esercizio'], [$facciata->id, $tetto->id]));
});

it('con delibere diverse nei piani si calcola se ogni spesa ha la sua competenza, e si ferma e lo dice se una non ce l\'ha', function () {
    $s = cpmViaVerdi('straordinaria');
    $giornale = cpmGiornale($s);
    $facciata = cpmVoce($s, 'Facciata', [[$s['generali'], 100]]);
    cpmPiano($s, [$facciata->id => null], '2026-02-01');
    cpmPiano($s, [$facciata->id => null], '2026-03-01');
    cpmSpesa($s, $giornale, $facciata, 100000, ['competenza_dal' => '2026-09-15', 'competenza_al' => '2026-09-15']);

    $datate = (new ConsuntivoPerPersona())->perEsercizio($s['esercizio']);
    // Il 15/09 U2 è di Rossi: 300,00.
    expect($datate['gestioni'][$s['gestione']->id]['non_calcolabile'])->toBeNull()
        ->and(cpsDi($datate, $s, 'Rossi', $facciata->id))->toBe(30000);

    cpmSpesa($s, $giornale, $facciata, 5000);
    $senza = (new ConsuntivoPerPersona())->perEsercizio($s['esercizio']);
    expect($senza['gestioni'][$s['gestione']->id]['non_calcolabile'])->toContain('date diverse')
        ->and($senza['persone'][$s['gestione']->id] ?? [])->toBe([]);
});

it('contano solo le delibere dei piani attivi dell\'esercizio: la straordinaria su due esercizi si calcola', function () {
    $s = cpmViaVerdi('straordinaria');
    $giornale = cpmGiornale($s);
    $facciata = cpmVoce($s, 'Facciata', [[$s['generali'], 100]]);
    cpmPiano($s, [$facciata->id => null], '2026-02-01');
    [$spento] = cpmPiano($s, [$facciata->id => null], '2026-03-01');
    DB::table('piani_rate')->where('id', $spento->id)->update(['attivo' => false]);
    $anno2027 = Esercizio::create(['condominio_id' => $s['condominio']->id, 'nome' => '2027', 'stato' => 'aperto', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
    legaAEsercizio($anno2027, $s['gestione']->id);
    cpmPiano($s, [$facciata->id => null], '2027-01-20', $anno2027);
    cpmSpesa($s, $giornale, $facciata, 100000);

    $lettura = (new ConsuntivoPerPersona())->perEsercizio($s['esercizio']);

    // La spesa senza fattura va alla delibera del piano del 2026 (01/02, U2 di Bianchi): Neri 500,00, Bianchi 300,00, Verdi 200,00, Rossi niente.
    expect($lettura['gestioni'][$s['gestione']->id]['non_calcolabile'])->toBeNull()
        ->and([cpsDi($lettura, $s, 'Neri', $facciata->id), cpsDi($lettura, $s, 'Bianchi', $facciata->id), cpsDi($lettura, $s, 'Rossi', $facciata->id), cpsDi($lettura, $s, 'Verdi', $facciata->id)])
        ->toBe([50000, 30000, 0, 20000]);
});

it('legge i capitoli dei piani del suo esercizio: con il capitolo nel piano del 2026 divide sul capitolo, con il piano di un altro esercizio no', function () {
    $s = cpmViaVerdi();
    $giornale = cpmGiornale($s);
    $scale = cpmTabella($s, 'Scale', ['U1' => 0, 'U2' => 400, 'U3' => 600]);
    $generali = cpmVoce($s, 'Spese generali', [[$s['generali'], 100]]);
    $cancelleria = cpmVoce($s, 'Cancelleria', [[$scale, 100]], 'spesa', 0, $generali->id);
    cpmSpesa($s, $giornale, $cancelleria, 10000);
    $anno2025 = Esercizio::create(['condominio_id' => $s['condominio']->id, 'nome' => '2025', 'stato' => 'chiuso', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31']);
    legaAEsercizio($anno2025, $s['gestione']->id);
    cpmPiano($s, [$generali->id => 10000], null, $anno2025);

    // Solo il piano del 2025 tiene il capitolo: la sottovoce si divide sulle sue tabelle (Scale): Bianchi 19,84, Rossi 20,16, Verdi 60,00.
    $altro = (new ConsuntivoPerPersona())->perEsercizio($s['esercizio']);
    expect([cpsDi($altro, $s, 'Neri', $cancelleria->id), cpsDi($altro, $s, 'Bianchi', $cancelleria->id), cpsDi($altro, $s, 'Rossi', $cancelleria->id), cpsDi($altro, $s, 'Verdi', $cancelleria->id)])
        ->toBe([0, 1984, 2016, 6000]);

    // Il piano del 2026 tiene il capitolo, che ha la tabella Generali: Neri 50,00, Bianchi 14,88, Rossi 15,12, Verdi 20,00, sul capitolo.
    cpmPiano($s, [$generali->id => 10000]);
    $suo = (new ConsuntivoPerPersona())->perEsercizio($s['esercizio']);
    expect([cpsDi($suo, $s, 'Neri', $generali->id), cpsDi($suo, $s, 'Bianchi', $generali->id), cpsDi($suo, $s, 'Rossi', $generali->id), cpsDi($suo, $s, 'Verdi', $generali->id)])
        ->toBe([5000, 1488, 1512, 2000])
        ->and($suo['gestioni'][$s['gestione']->id]['diviso_su'])->toBe([$cancelleria->id => [$generali->id => 10000]]);
    cpsQuadra($suo, $s['gestione']->id);
});

it('le uscite, le persone e le non attribuite hanno le stesse chiavi e tornano al centesimo, anche con le sottovoci', function () {
    $s = cpmViaVerdi();
    $giornale = cpmGiornale($s);
    $scale = cpmTabella($s, 'Scale', ['U1' => 0, 'U2' => 400, 'U3' => 600]);
    $generali = cpmVoce($s, 'Spese generali', [[$s['generali'], 100]]);
    $cancelleria = cpmVoce($s, 'Cancelleria', [[$scale, 100]], 'spesa', 0, $generali->id);
    $postali = cpmVoce($s, 'Postali', [], 'spesa', 0, $generali->id);
    $ascensore = cpmVoce($s, 'Ascensore', []);
    $pulizie = cpmVoce($s, 'Pulizie', [[$s['generali'], 100]]);
    cpmPiano($s, [$generali->id => 10000, $ascensore->id => 5000, $pulizie->id => 330000]);
    cpmSpesa($s, $giornale, $cancelleria, 6001);
    cpmSpesa($s, $giornale, $postali, 4000);
    cpmSpesa($s, $giornale, $ascensore, 5000);
    cpmSpesa($s, $giornale, $pulizie, 330000);

    $lettura = (new ConsuntivoPerPersona())->perEsercizio($s['esercizio']);

    // Il piano tiene il capitolo con la tabella: le due sottovoci si sommano sul capitolo (€ 100,01). L'ascensore senza tabelle: € 50,00 non attribuiti.
    $g = $lettura['gestioni'][$s['gestione']->id];
    expect($g['uscite'])->toEqual([$generali->id => 10001, $ascensore->id => 5000, $pulizie->id => 330000])
        ->and($g['non_attribuite'])->toBe([$ascensore->id => 5000])
        ->and($g['diviso_su'])->toEqual([$cancelleria->id => [$generali->id => 6001], $postali->id => [$generali->id => 4000], $ascensore->id => [$ascensore->id => 5000], $pulizie->id => [$pulizie->id => 330000]]);
    cpsQuadra($lettura, $s['gestione']->id);
});
