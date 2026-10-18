<?php

/**
 * Il fermo del piano legge le stesse voci che legge il motore, e solo quelle.
 *
 * Il motore del riparto sceglie le voci in modo preciso: le righe della pivot del piano che stanno nel piano dei conti della sua gestione; se la pivot è
 * vuota, le radici di quel piano dei conti che nessun altro piano attivo della gestione ha già impegnato; per un capitolo con la copia ripartisce quella
 * copia sulle sue tabelle (se le ha, senza guardare i figli) oppure sui figli nati fino alla creazione del piano, in proporzione al loro importo (con la
 * somma dei pesi positiva) o in parti uguali. Il fermo che rifiuta il ricalcolo di una voce di spesa negativa deve scattare dove il motore addebiterebbe
 * in positivo un importo negativo e in nessun altro punto: un rifiuto dove il motore ha ragione, o una frase che promette una cifra sbagliata, sono
 * errori quanto un ricalcolo che passa.
 *
 * Ogni caso costruisce il dato come lo lasciavano le versioni precedenti (voce e piano positivi, poi importo e copia riscritti) e confronta il rifiuto, o il
 * totale che il motore chiede, con il conto a mano scritto nel test.
 *
 * Fonte dell'atteso: i conti a mano di ogni caso (spesa base € 1.200,00, copia del capitolo € 400,00, i figli indicati) e il comportamento del motore
 * (`processaConti`), che qui non cambia.
 *
 * Cosa resta scoperto: la copia negativa su una voce con i figli; la modalità «piano approvato» (`Beta52VoceEntrataFermoTest`); le stampe in sola lettura; la
 * schermata. Il testo del rifiuto per il figlio è provato nelle parti che lo definiscono: il nome del figlio, il motivo (il totale supererebbe quello del capitolo)
 * e l'assenza della frase della voce negativa, che per il figlio non è vera.
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Models\Gestionale\PianoRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
});

function be52SelezioneMessaggio(): string
{
    return (string) (be52Flash()['message'] ?? '');
}

/** Il piano senza voci nella pivot, con la spesa base di € 1.200,00 e una voce «Rimborso» di € 240,00 che il test poi riscrive. */
function be52SelezioneGlobale($test): array
{
    $s = be52Vuoto($test);
    $base = be52Voce($test, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $voce = be52Voce($test, $s, $s['pc'], 'Rimborso', 'spesa', '240,00');

    return [$s, be52PianoSenzaPivot($s), $voce, $base];
}

// ─── Il piano senza voci nella pivot ────────────────────────────────────────────────────────────────────────────

it('un piano senza voci nella pivot si ferma su una radice di spesa a −€ 240,00 e la nomina', function () {
    [$s, $piano, $voce] = be52SelezioneGlobale($this);
    DB::table('conti')->where('id', $voce->id)->update(['importo' => -24000]);

    be52Ricalcola($this, $s, $piano->fresh());

    expect(be52Flash())->toBe(['type' => 'error', 'message' => BE52_FERMO_TESTA_UNA.BE52_FERMO_SENZA_VOCI.BE52_FERMO_CORREGGI_LA_VOCE])
        ->and(be52Quote($piano))->toBe([])
        ->and(be52Pivot($piano))->toBe([]);
});

it('controprova — una radice negativa già impegnata in un altro piano attivo della gestione non ferma il piano senza pivot', function () {
    [$s, $piano, $voce] = be52SelezioneGlobale($this);
    $altra = be52Voce($this, $s, $s['pc'], 'Altra voce', 'spesa', '100,00');
    $altroPiano = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Altro piano', 'stato' => 'bozza', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => true, 'attivo' => true]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $altroPiano->id, 'conto_id' => $altra->id, 'importo' => 10000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conti')->where('id', $altra->id)->update(['importo' => -10000]);

    // Il piano legge «Spese generali» e «Rimborso» (€ 1.440,00): la voce impegnata altrove non è sua.
    expect(be52RicalcolaETotale($this, $s, $piano))->toBe(['success', 144000]);
});

it('togliere l\'unica voce di un piano, quando è negativa, si rifiuta: senza voci la pivot si legge come piano globale e la voce tornerebbe dentro', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $voce = be52Voce($this, $s, $s['pc'], 'Rimborso', 'spesa', '240,00');
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), ['tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Solo il rimborso',
        'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 1, 'capitoli_ids' => [$voce->id]])->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Solo il rimborso')->firstOrFail();
    DB::table('conti')->where('id', $voce->id)->update(['importo' => -24000]);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $voce->id)->update(['importo' => -24000]);
    $quote = be52Quote($piano);

    be52Detach($this, $s, $piano->fresh(), $voce->id);

    expect(be52Flash()['type'] ?? null)->toBe('error')
        ->and(be52SelezioneMessaggio())->toContain('«Rimborso»')
        ->and(be52SelezioneMessaggio())->toContain(BE52_FERMO_SENZA_VOCI)
        ->and(be52Pivot($piano->fresh()))->toBe([$voce->id => -24000])
        ->and($piano->fresh()->stato->value)->toBe('bozza')
        ->and(be52Quote($piano))->toBe($quote);
});

// ─── I figli di un capitolo ─────────────────────────────────────────────────────────────────────────────────────

/** @return array<string, array{0: array<string, int>, 1: ?int, 2: bool, 3: int}> caso => [figli in centesimi, copia del capitolo, il fermo scatta, il totale che il motore chiede] */
function be52CasiDeiFigli(): array
{
    return [
        'controprova — figli −€ 500,00 e −€ 100,00: il motore divide in parti uguali e il totale è giusto' => [['Ascensore' => -50000, 'Rimborso figlio' => -10000], 40000, false, 160000],
        'controprova — figli € 100,00 e −€ 200,00: somma negativa, il totale è giusto' => [['Ascensore' => 10000, 'Rimborso figlio' => -20000], 40000, false, 160000],
        'figli € 500,00 e −€ 100,00: il peso del figlio negativo gonfia il totale a € 1.800,00' => [['Ascensore' => 50000, 'Rimborso figlio' => -10000], 40000, true, 180000],
    ];
}

it('un figlio negativo ferma il piano solo dove il motore ripartisce la copia del capitolo sui figli e ne esce un totale sbagliato', function (array $figli, ?int $copia, bool $scatta, int $totaleDelMotore) {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $piano = be52PianoDallaRotta($this, $s);
    be52CapitoloConFigli($this, $s, $piano, $figli, $copia);
    $quoteDi = be52Quote($piano->fresh());

    be52Ricalcola($this, $s, $piano->fresh());
    $esito = be52Flash();

    if ($scatta) {
        expect($esito['type'] ?? null)->toBe('error')
            ->and(str_contains(be52SelezioneMessaggio(), '«Rimborso figlio»'))->toBeTrue('il rifiuto nomina il figlio negativo')
            ->and(str_contains(be52SelezioneMessaggio(), 'supererebbe quello del capitolo'))->toBeTrue('il rifiuto dice che il totale supererebbe quello del capitolo')
            ->and(str_contains(be52SelezioneMessaggio(), 'addebiterebbe in positivo'))->toBeFalse('la frase della voce negativa non vale per il figlio che il motore pesa')
            ->and(be52Quote($piano->fresh()))->toBe($quoteDi);
    } else {
        expect($esito['type'] ?? null)->toBe('success')
            ->and(be52Totale($piano->fresh()))->toBe($totaleDelMotore);
    }
})->with(fn () => be52CasiDeiFigli());

it('un figlio negativo senza la copia sul capitolo ferma il piano con la frase della voce: il motore lo addebita con abs per € 100,00', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $piano = be52PianoDallaRotta($this, $s);
    be52CapitoloConFigli($this, $s, $piano, ['Ascensore' => 50000, 'Rimborso figlio' => -10000], null);

    be52Ricalcola($this, $s, $piano->fresh());

    expect(be52Flash())->toBe(['type' => 'error', 'message' => 'Il piano non si ricalcola: la voce di spesa «Rimborso figlio» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, per € 100,00.'
        .BE52_FERMO_CORREGGI_LA_VOCE]);
});

it('controprova — un figlio negativo nato dopo la creazione del piano non ferma il piano: il motore lo salta', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $piano = be52PianoDallaRotta($this, $s);
    be52CapitoloConFigli($this, $s, $piano, ['Ascensore' => 50000, 'Rimborso figlio' => -10000], 40000, false, ['Rimborso figlio' => now()->addDay()->toDateTimeString()]);

    // La copia di € 400,00 va tutta sul figlio che c'era alla creazione: € 1.200,00 + € 400,00.
    expect(be52RicalcolaETotale($this, $s, $piano))->toBe(['success', 160000]);
});

it('controprova — un capitolo con la copia e le tabelle proprie non ferma il piano per un figlio negativo: il motore non guarda i figli', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $piano = be52PianoDallaRotta($this, $s);
    $padre = be52CapitoloConFigli($this, $s, $piano, ['Ascensore' => 50000, 'Rimborso figlio' => -10000], 40000, true);
    expect(DB::table('conto_tabella_millesimale')->where('conto_id', $padre->id)->count())->toBe(1);

    expect(be52RicalcolaETotale($this, $s, $piano))->toBe(['success', 160000]);
});

// ─── Le voci di altri piani dei conti e di altre gestioni ───────────────────────────────────────────────────────

it('controprova — una riga della pivot su una voce negativa di un altro piano dei conti non ferma il piano: il motore la ignora', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $piano = be52PianoDallaRotta($this, $s);
    $altra = be52GestioneStraordinaria($s);
    $lavori = be52Voce($this, array_merge($s, $altra), $altra['pc'], 'Lavori', 'spesa', '500,00');
    DB::table('conti')->where('id', $lavori->id)->update(['importo' => -50000]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $lavori->id, 'importo' => -50000, 'created_at' => now(), 'updated_at' => now()]);

    expect(be52RicalcolaETotale($this, $s, $piano))->toBe(['success', 120000]);
});

it('controprova — una voce negativa nel piano dei conti di un\'altra gestione non ferma il piano ordinario', function () {
    [$s, $piano] = be52SelezioneGlobale($this);
    $altra = be52GestioneStraordinaria($s);
    $lavori = be52Voce($this, array_merge($s, $altra), $altra['pc'], 'Lavori', 'spesa', '500,00');
    DB::table('conti')->where('id', $lavori->id)->update(['importo' => -50000]);

    expect(be52RicalcolaETotale($this, $s, $piano))->toBe(['success', 144000]);
});

/** Un piano straordinario con una fattura da € 1.000,00 addebitata a un'unità, e una voce «Rimborso» negativa nel piano dei conti della sua gestione. */
function be52PianoStraordinarioConVoceNegativa($test): array
{
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    $s = be52Vuoto($test);
    $st = be52GestioneStraordinaria($s);
    $s2 = array_merge($s, $st);
    $rimborso = be52Voce($test, $s2, $st['pc'], 'Rimborso', 'spesa', '240,00');
    DB::table('conti')->where('id', $rimborso->id)->update(['importo' => -24000]);
    $fattura = be52FatturaAdPersonam($s2, be52Fornitore(), $s['unita']['A']->id, 1000.00, 'FT-FACCIATA');
    $piano = PianoRate::create(['gestione_id' => $st['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Facciata 2026', 'stato' => 'bozza', 'tipo' => 'straordinario',
        'numero_rate' => 2, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-03-10', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => true,
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera di prova', 'data_delibera_assemblea' => '2026-02-01']);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 100000]);

    return [$s2, $piano, $fattura];
}

it('controprova — un piano straordinario non si ferma per una voce negativa nel piano dei conti della sua gestione: le sue voci sono fatture', function () {
    [$s, $piano] = be52PianoStraordinarioConVoceNegativa($this);

    expect(be52RicalcolaETotale($this, $s, $piano))->toBe(['success', 100000]);
});
