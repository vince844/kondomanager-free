<?php

/**
 * 1.11.0-beta.54 (Coda 340, decisione 107.2) — una riga attiva a quota zero chiusa il giorno prima di un'altra della stessa tipologia è un predecessore.
 *
 * La regola D7 (`RisolutoreTitolari::haPredecessoreChiuso`): la data d'inizio di una riga conta, come decorrenza, solo se sulla stessa tipologia c'è una
 * riga chiusa il giorno prima. La catena del ripiego, il peso e i giorni scoperti la leggono su tutte le righe attive; `UnitaMista` la leggeva solo sulle righe
 * con una quota maggiore di zero, e una riga a quota zero davanti a un'altra della stessa tipologia faceva giudicare l'unità in due modi: per i pesi la
 * decorrenza contava, per il controllo dell'unità mista no. Ora `UnitaMista` legge la regola come il resto del motore, una sola.
 *
 * Il dato è anomalo, ma «Associa» e «Modifica associazione» lo accettano (la quota va da 0 a 100): Anna proprietaria piena al 50 %, Ugo proprietario al
 * 50 % fino al 30/4, dal 1/5 Ugo nudo proprietario al 50 % ed Elsa usufruttuaria al 50 %, più una riga d'usufrutto di Zeno a quota zero dal 2019 al 30/4. La
 * voce è tutta sull'«Inquilino», e nessun inquilino: i giorni vanno alla catena del ripiego.
 *
 * Fonte dell'atteso: il conto a mano. € 1.200,00 sui 365 giorni. Fino al 30/4 (120 giorni) pagano Anna e Ugo, 50 e 50: 120000 × ½ × 120/365 = 19726,03
 * a testa. Dal 1/5 (245 giorni) pagano insieme Elsa usufruttuaria e Anna proprietaria piena, per le quote (decisione 31.1): 120000 × ½ × 245/365 = 40273,97 a
 * testa. Anna 19726 + 40274 = 60000, Ugo 19726, Elsa 40274. Senza la riga a quota zero la riga di Elsa è «da sempre» (la D7 di sempre): paga 120000 per tutto
 * l'anno, e il caso resta com'era.
 *
 * Che cosa NON copre: i piani già emessi, che non si riscrivono; un ricalcolo di un piano generato prima (stessa regola, stessa lettura).
 */

use App\Services\Riparto\RisolutoreTitolari;
use App\Services\Riparto\UnitaMista;
use App\Support\PeriodoCompetenza;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../Gestionale/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/../Gestionale/Beta51PassaggiScenari.php';

/**
 * L'unità senza inquilini, la voce tutta sull'«Inquilino», le righe date inserite da «Associa» (la rotta della pagina), il piano generato. Restituisce quanto
 * paga ognuno sull'unità, per nome.
 *
 * @param list<array{0: string, 1: string, 2: int|float, 3: string, 4: ?string}> $righe [nome, ruolo, quota, dal, al]
 * @return array<string, int>
 */
function ump54Quote($test, array $righe): array
{
    sl53Amministratore($test);
    $s = fclScenario([], ['ripartizione' => ['inquilino' => 100], 'inquilini' => []]);
    foreach (array_unique(array_column($righe, 0)) as $nome) {
        fclPersona($s, $nome);
    }
    foreach ($righe as [$nome, $ruolo, $quota, $dal, $al]) {
        $test->actingAs($test->user)->post(route('admin.gestionale.immobili.anagrafiche.store', [$s['c'], $s['unita']]), [
            'anagrafica_id' => $s['persone'][$nome]->id, 'tipologia' => $ruolo, 'quota' => $quota, 'data_inizio' => $dal, 'data_fine' => $al,
        ])->assertSessionHasNoErrors();
    }
    fclGenera($s);

    $nomi = collect($s['persone'])->mapWithKeys(fn ($a, string $nome) => [(int) $a->id => $nome])->all();
    $quote = [];
    foreach (DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->get(['rate_quote.anagrafica_id', 'rate_quote.importo']) as $q) {
        $quote[$nomi[(int) $q->anagrafica_id] ?? '?'] = ($quote[$nomi[(int) $q->anagrafica_id] ?? '?'] ?? 0) + (int) $q->importo;
    }
    ksort($quote);

    return $quote;
}

const UMP54_SENZA_LA_ZERO = [
    ['Anna', 'proprietario', 50, '2019-01-01', null],
    ['Ugo', 'proprietario', 50, '2019-01-01', '2026-04-30'],
    ['Ugo', 'nuda_proprietario', 50, '2026-05-01', null],
    ['Elsa', 'usufruttuario', 50, '2026-05-01', null],
];

it('Coda 340 — con una riga d\'usufrutto a quota zero chiusa il 30/4 (inserita da «Associa»), l\'usufrutto di Elsa dal 1/5 è una decorrenza: Anna e Elsa pagano insieme dal 1/5, per le quote', function () {
    $quote = ump54Quote($this, [...UMP54_SENZA_LA_ZERO, ['Zeno', 'usufruttuario', 0, '2019-01-01', '2026-04-30']]);

    // Conto a mano: Anna 19726 + 40274, Ugo 19726, Elsa 40274. Prima della correzione Elsa pagava per intero i suoi 245 giorni (80548) e Anna solo i primi 120 (19726).
    expect($quote)->toBe(['Anna' => 60000, 'Elsa' => 40274, 'Ugo' => 19726]);
});

it('Coda 340 — senza la riga a quota zero la riga di Elsa è «da sempre» (la D7 di sempre): paga tutto l\'anno, e il caso resta com\'era', function () {
    expect(ump54Quote($this, UMP54_SENZA_LA_ZERO))->toBe(['Elsa' => 120000]);
});

it('UnitaMista — la riga a quota zero chiusa il giorno prima è il predecessore della riga che la segue: nel tratto fino al 30/4 l\'usufrutto di Elsa non c\'è, e l\'unità è mista dal 1/5', function () {
    $riga = fn (int $id, string $tipologia, float $quota, ?string $dal, ?string $al) => (object) ['id' => $id, 'tipologia' => $tipologia, 'quota' => $quota, 'attivo' => true,
        'data_inizio' => $dal, 'data_fine' => $al, 'immobile_id' => 1];
    $anna = $riga(1, 'proprietario', 50, '2019-01-01', null);
    $ugo = $riga(2, 'proprietario', 50, '2019-01-01', '2026-04-30');
    $elsa = $riga(4, 'usufruttuario', 50, '2026-05-01', null);
    $unita = collect([$anna, $ugo, $riga(3, 'nuda_proprietario', 50, '2026-05-01', null), $elsa, $riga(5, 'usufruttuario', 0, '2019-01-01', '2026-04-30')]);
    $anno = new PeriodoCompetenza('2026-01-01', '2026-12-31');

    $gemelle = (new UnitaMista(new RisolutoreTitolari()))->righeGemelle($unita, collect([$elsa]), 'usufruttuario', $anno);

    // Conto a mano: fino al 30/4 Anna e Ugo fanno 100 e nessun usufruttuario è in vigore; dal 1/5 Elsa e Anna fanno 100. L'unità è mista, e le righe da unire a Elsa
    // sono quelle del proprietario pieno (Anna, e Ugo fino al 30/4).
    expect($gemelle->pluck('id')->all())->toBe([1, 2]);
});
