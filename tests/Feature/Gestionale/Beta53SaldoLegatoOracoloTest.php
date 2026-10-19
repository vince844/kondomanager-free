<?php

/**
 * 1.11.0-beta.53 (Coda 244) — le righe legate della fermata portano i netti all'oracolo, con e senza un riporto.
 *
 * L'oracolo della vendita del 1/11 (fermata € 60,16 a carico di Ugo, verso Elsa) è quello della coppia scritta come se la vendita fosse già
 * nei dati: Elsa 20054, Ines 17852, Ugo 82094, totale 120000 (le quote pure del piano più tutte le righe di saldo dell'unità e della
 * gestione, `fds50Netti`). Un riporto di una persona si somma alla sua cifra e al totale. Prima, con un riporto, la riga di quella persona
 * si rifiutava (il posto era occupato) e passava solo l'altra: € 60,16 di troppo, o di meno, nel totale.
 *
 * I conti a mano: senza le righe Elsa 14038, Ines 17852, Ugo 88110 (misurati sullo scenario); con le righe Ugo 88110 − 6016 = 82094, Elsa
 * 14038 + 6016 = 20054. Con il riporto di Ugo (+12345) Ugo 94439 e totale 132345; con il riporto a credito (−4321) Ugo 77773 e totale 115679;
 * con il riporto di Elsa (+7000) Elsa 27054 e totale 127000.
 *
 * Che cosa NON copre: le rate delle persone dopo un ricalcolo del piano (il piano nuovo prende le righe come ogni saldo, qui si guarda la
 * distribuzione dei saldi che la generazione assorbe); la nota di solidarietà (in `Beta53NotaSolidarietaLegataTest`); MySQL.
 */

use App\Actions\PianoRate\GenerateSaldiAction;
use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

/** Le due righe della fermata dalla porta, legate alla vendita; restituisce gli errori di ognuna, per nome. */
function sl53oDueRighe($test, array $s): array
{
    $errori = [];
    foreach ([['Ugo', -$s['cifra']], ['Elsa', $s['cifra']]] as [$chi, $cents]) {
        $errori[$chi] = sl53Errori(sl53Posta($test, $s, $chi, $cents, (int) $s['vendita']->id));
    }

    return $errori;
}

it('senza riporto le due righe legate arrivano all\'oracolo, ognuna con il suo passaggio', function () {
    $s = sl53Scenario($this);
    expect(fds50Netti($s))->toBe(['Elsa' => 14038, 'Ines' => 17852, 'Ugo' => 88110]);

    expect(sl53oDueRighe($this, $s))->toBe(['Ugo' => [], 'Elsa' => []]);

    expect(fds50Netti($s))->toBe(['Elsa' => 20054, 'Ines' => 17852, 'Ugo' => 82094])
        ->and(array_sum(fds50Netti($s)))->toBe(120000)
        ->and(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6016, 'Ugo' => -6016]);
});

it('con il riporto di Ugo (€ 123,45) tutte e due le righe legate si scrivono e il totale è 132345', function () {
    $s = sl53Scenario($this, ['Ugo' => 12345]);

    expect(sl53oDueRighe($this, $s))->toBe(['Ugo' => [], 'Elsa' => []]);

    expect(fds50Netti($s))->toBe(['Elsa' => 20054, 'Ines' => 17852, 'Ugo' => 94439])
        ->and(array_sum(fds50Netti($s)))->toBe(132345);
});

it('con il riporto di Ugo a credito (−€ 43,21) le righe legate si scrivono e il totale è 115679', function () {
    $s = sl53Scenario($this, ['Ugo' => -4321]);

    expect(sl53oDueRighe($this, $s))->toBe(['Ugo' => [], 'Elsa' => []]);

    expect(fds50Netti($s))->toBe(['Elsa' => 20054, 'Ines' => 17852, 'Ugo' => 77773])
        ->and(array_sum(fds50Netti($s)))->toBe(115679);
});

it('il verso opposto, con il riporto di Elsa (€ 70,00): le righe legate si scrivono e il totale è 127000', function () {
    $s = sl53Scenario($this, ['Elsa' => 7000]);

    expect(sl53oDueRighe($this, $s))->toBe(['Ugo' => [], 'Elsa' => []]);

    expect(fds50Netti($s))->toBe(['Elsa' => 27054, 'Ines' => 17852, 'Ugo' => 82094])
        ->and(array_sum(fds50Netti($s)))->toBe(127000);
});

it('il piano dopo assorbe le righe legate come ogni saldo: la distribuzione dei saldi e il piano sulle due righe', function () {
    // Il riporto di Ugo è già nel «Preventivo 2026» (scritto prima della sua generazione). Il «Conguaglio 2026» prende i saldi liberi della
    // gestione: la coppia della fine (Ines −18148, Ugo +18148), quella della vendita (Ugo −38, Elsa +38) e le due righe legate. Ugo: 18148 −
    // 38 − 6016 = 12094; Elsa: 38 + 6016 = 6054; Ines: −18148.
    $s = sl53Scenario($this, ['Ugo' => 12345]);
    expect(sl53oDueRighe($this, $s))->toBe(['Ugo' => [], 'Elsa' => []]);
    $piano = sl53SecondoPiano($s, genera: false);

    $distribuzione = collect(app(GenerateSaldiAction::class)->execute($piano, $s['g']))
        ->mapWithKeys(fn ($perUnita, $id) => [collect($s['persone'])->search(fn ($a) => (int) $a->id === (int) $id) => (int) ($perUnita[$s['unita']->id]['importo'] ?? 0)])
        ->sortKeys()->all();
    expect($distribuzione)->toBe(['Elsa' => 6054, 'Ines' => -18148, 'Ugo' => 12094]);

    fds50Genera($s, $piano);
    expect(collect(sl53Righe($s))->filter(fn ($x) => $x[4] !== null)->map(fn ($x) => [$x[1], $x[2], $x[5], $x[6]])->values()->all())
        ->toBe([['Ugo', -6016, true, (int) $piano->id], ['Elsa', 6016, true, (int) $piano->id]]);
});

it('parte negativa (l\'entrata supera la spesa nei giorni di Elsa): le righe rovesciate si scrivono legate e si tolgono con il passaggio', function () {
    // Lo scenario di G1 della .50 (`StrettoBeta50MotoreTest`): l'«Affitto antenna» € 400,00 di entrata dal 1/10, la vendita del 1/10 si ferma su
    // € 29,26 a credito di Elsa (−2926). Le righe: Ugo +2926 (a debito), Elsa −2926 (a credito). I netti fatti a mano di G1: Elsa −9753, Ines
    // 17852, Ugo 71901. Annullata la vendita le righe si tolgono.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Elsa']);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-09-30');
    $this->travel(1)->minutes();
    $s['vendita'] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    expect(collect(fds50Fermate($s['vendita']))->map(fn ($f) => (int) $f['cifra'])->all())->toBe([-2926]);
    $this->travel(1)->minutes();

    foreach ([['Ugo', 2926], ['Elsa', -2926]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id)))->toBe([]);
    }
    expect(fds50Netti($s))->toBe(['Elsa' => -9753, 'Ines' => 17852, 'Ugo' => 71901])
        ->and(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => -2926, 'Ugo' => 2926]);

    expect(sl53Messaggio(sl53Annulla($this, $s, $s['vendita']))['message'])->toStartWith('Passaggio annullato.');
    expect(collect(sl53Righe($s))->filter(fn ($x) => $x[3] === null && in_array($x[2], [2926, -2926], true))->values()->all())->toBe([]);
});

it('gestione su due esercizi: la riga legata scritta sull\'esercizio aperto entra nel piano dell\'altro esercizio della stessa gestione', function () {
    // La generazione prende i saldi della gestione senza guardare l'esercizio (`GenerateSaldiAction`): la riga legata non fa eccezione.
    $s = sl53Scenario($this);
    $dopo = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($dopo, $s['g']->id);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);
    $riga = Saldo::where('anagrafica_id', $s['persone']['Elsa']->id)->where('saldo_iniziale', 6016)->firstOrFail();
    expect((int) $riga->esercizio_id)->toBe((int) $s['e']->id)
        ->and($riga->passaggio_id === null ? null : (int) $riga->passaggio_id)->toBe((int) $s['vendita']->id);

    $piano = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $dopo->id, 'nome' => 'Preventivo 2027', 'stato' => 'bozza',
        'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2027-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
    app(GeneratePianoRateAction::class)->execute($piano->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: generazione del 2027', esercizio: $dopo);

    $riga->refresh();
    expect((bool) $riga->is_applicato)->toBeTrue()
        ->and((int) $riga->piano_rate_id)->toBe((int) $piano->id)
        ->and($riga->passaggio_id === null ? null : (int) $riga->passaggio_id)->toBe((int) $s['vendita']->id);
});
