<?php

/**
 * 1.11.0-beta.53 (Coda 244, decisione 100) — «Annulla il conguaglio» dice i fatti dei saldi del Wallet legati allo stesso passaggio.
 *
 * Quando la coppia di un passaggio è in un piano che non si riscrive più, il rifiuto di «Annulla il conguaglio» indica la correzione: un saldo
 * manuale di segno opposto legato a quel passaggio dal Wallet. Se poi il piano si riapre e il conguaglio si annulla, la coppia sparisce e la
 * correzione resta: da sola conta come la coppia rovesciata. Il programma non sa se quelle righe sono la correzione della coppia o la parte di
 * una fermata, e non le tocca: prima di confermare (lo storico) e nel messaggio dice, per gestione, quanto sommano oggi per ogni persona e quanto
 * vale la parte della fermata di questo passaggio come è oggi (zero se il passaggio non ne ha una, se il conguaglio su cui si era fermata è stato
 * annullato, se un inizio l'ha azzerata); quali righe non si modificano più e perché (in un piano che non si riscrive più, in un esercizio che non
 * è quello aperto); e il passo dopo dal Wallet. Ogni frase è seguita dalle rotte (`sl53SeguiIFatti`), fino alle somme giuste.
 *
 * Quando la parte della fermata non si legge da sola (le fermate di più passaggi sulle stesse persone, la fermata di chi era entrato con un
 * passaggio già fermato) la frase la tace e il passo è al condizionale: «se non sono la parte della fermata di questo passaggio, correggi …». Mai un
 * «correggi» senza condizione, che spingerebbe a toccare righe che sono già la parte.
 *
 * Fonte dell'atteso: la regola dei saldi legati (Coda 244) e il conto a mano scritto in ogni test.
 *
 * Che cosa NON copre: le righe legate della fine con un coinquilino che resta (la fine non ha coppia, il suo conguaglio non si annulla); MySQL.
 */

use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use App\Services\Subentro\FermataDopoScrivi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

/**
 * La vendita di Ugo a Elsa il 1/9, senza fine prima (nessuna fermata): la coppia (Elsa € 0,77 a debito, Ugo € 0,77 a credito) assorbita da un
 * secondo piano che emette; il rifiuto indica la correzione legata, che si registra dalla porta; poi il piano si riapre dalla sua pagina
 * (annullate le emissioni, riportato in bozza, eliminato). Restituisce lo scenario e la vendita.
 */
function sl53kCorrezioneLegata($test, bool $terzoPiano = false, array $dopoIlTerzo = [], array $senza = []): array
{
    $s = fds50Scenario();
    $test->travel(1)->minutes();
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $test->travel(1)->minutes();
    $vendita = fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-01'))['subentro'];
    $test->travel(1)->minutes();
    $coppia = fds50Coppia($s, $vendita);
    expect($coppia)->toBe(['Elsa' => 77, 'Ugo' => -77]);

    $p2 = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Rate di fine anno', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-11-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
    fds50Genera($s, $p2);
    fds50Emetti($test, $s, '2026-12-31', $p2);
    expect(sl53Errori(sl53AnnullaConguaglio($test, $s, $vendita))['conguaglio'][0] ?? '')->toContain('legato a quel passaggio dal Wallet');

    $correggi = function (array $chi) use ($test, $s, $coppia, $vendita, $senza) {
        foreach (array_diff_key($coppia, array_flip($senza)) as $nome => $cents) {
            if (in_array($nome, $chi, true)) {
                expect(sl53Errori(sl53Posta($test, $s, $nome, -$cents, (int) $vendita->id)))->toBe([]);
            }
        }
    };
    $correggi(array_values(array_diff(array_keys($coppia), $dopoIlTerzo)));
    $test->travel(1)->minutes();
    if ($terzoPiano) {
        // Un terzo piano assorbe le due correzioni, libere, ed emette: la coppia resta nel secondo.
        $p3 = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Rate di dicembre', 'stato' => 'approvato',
            'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-12-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
        fds50Genera($s, $p3);
        fds50Emetti($test, $s, '2026-12-31', $p3);
        expect(Saldo::where('passaggio_id', $vendita->id)->pluck('piano_rate_id')->map(fn ($id) => (int) $id)->unique()->values()->all())->toBe([(int) $p3->id]);
    }
    $correggi($dopoIlTerzo);

    foreach (DB::table('rate')->where('piano_rate_id', $p2->id)->where('stato', 'emessa')->pluck('id') as $rataId) {
        $test->actingAs($test->user)->from('/piano')->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $p2, $rataId]));
    }
    $test->actingAs($test->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $p2]), ['approvato' => false]);
    $test->actingAs($test->user)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $p2]));
    expect(PianoRate::find($p2->id))->toBeNull()
        ->and(Saldo::where('subentro_id', $vendita->id)->get()->every(fn (Saldo $x) => ! $x->is_applicato))->toBeTrue();

    return [$s, $vendita];
}

/** L'apertura dell'avviso: annullata la coppia, una correzione legata conta da sola. */
const SL53K_APRE = 'Annullato il conguaglio, un saldo del Wallet legato a questo passaggio che lo correggeva conta da solo. ';
/** I fatti della correzione della coppia della vendita del 1/9 (€ 0,77), senza fermata. */
const SL53K_CORREZIONE = SL53K_APRE . 'I saldi del Wallet legati a questo passaggio sulla gestione Ordinaria 2026 sommano oggi € 0,77 a credito di Elsa e € 0,77 a debito di Ugo; la parte da regolare a mano con questo passaggio è € 0,00: non ha una fermata su questa gestione.';
/** I fatti delle righe della fermata della vendita del 1/11 (€ 60,16). */
const SL53K_FERMATA = SL53K_APRE . 'I saldi del Wallet legati a questo passaggio sulla gestione Ordinaria 2026 sommano oggi € 60,16 a credito di Ugo e € 60,16 a debito di Elsa';

it('la correzione legata della coppia di una vendita senza fermata: «Annulla il conguaglio» dice quanto somma e che la parte è € 0,00, prima di confermare e nel messaggio, e non la tocca; seguito, le righe sommano zero', function () {
    [$s, $vendita] = sl53kCorrezioneLegata($this);
    $atteso = SL53K_CORREZIONE . ' L\'annullamento del conguaglio non li cambia: correggi dal Wallet i saldi che si modificano.';

    $voce = fds50Storico($s, $vendita->fresh());
    expect($voce['conguaglio']['stato'] ?? null)->toBe('proposto')
        ->and($voce['conguaglio']['avvisi'] ?? null)->toBe([$atteso]);

    $r = sl53AnnullaConguaglio($this, $s, $vendita);
    expect(sl53Errori($r))->toBe([]);
    $messaggio = sl53Messaggio($r);
    expect($messaggio['type'])->toBe('warning')
        ->and($messaggio['message'])->toStartWith('Conguaglio annullato: 2 righe tolte dai saldi della gestione.')
        ->and($messaggio['message'])->toEndWith(' ' . $atteso);

    // Le righe legate restano: la coppia è tolta, la correzione no (decide l'amministratore). Seguito, le righe vanno a zero dal Wallet.
    expect(fds50Coppia($s, $vendita))->toBe([])
        ->and(sl53Legate($s, $vendita))->toBe(['Elsa' => -77, 'Ugo' => 77]);
    expect(sl53SeguiIFatti($this, $s, $vendita, ['Elsa' => 0, 'Ugo' => 0], $atteso))->toBe([]);
    expect(sl53Legate($s, $vendita))->toBe(['Elsa' => 0, 'Ugo' => 0]);
});

it('le righe della fermata legate alla stessa vendita: l\'avviso dice che sono già la parte della fermata, e non propone niente', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);
    $this->travel(1)->minutes();

    $atteso = SL53K_FERMATA . ': sono già la parte della fermata di questo passaggio.';
    expect(fds50Storico($s, $s['vendita']->fresh())['conguaglio']['avvisi'] ?? [])->toBe([$atteso]);

    $r = sl53AnnullaConguaglio($this, $s, $s['vendita']);
    expect(sl53Messaggio($r)['type'])->toBe('warning')
        ->and(sl53Messaggio($r)['message'])->toEndWith(' ' . $atteso)
        ->and(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6016, 'Ugo' => -6016]);
});

it('le righe della fermata legate alla vendita, con il conguaglio della fine annullato: la parte della fermata è € 0,00, e l\'avviso dice perché; seguito, le righe sommano zero', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);
    $this->travel(1)->minutes();
    expect(sl53Errori(sl53AnnullaConguaglio($this, $s, $s['fine'])))->toBe([]);

    $atteso = SL53K_FERMATA . '; la parte della fermata di questo passaggio è € 0,00: il conguaglio su cui si era fermata è stato annullato. L\'annullamento del conguaglio non li cambia: correggi dal Wallet i saldi che si modificano.';
    expect(fds50Storico($s, $s['vendita']->fresh())['conguaglio']['avvisi'] ?? null)->toBe([$atteso]);
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => 0, 'Elsa' => 0], $atteso))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 0, 'Ugo' => 0]);
});

it('le righe della fermata legate alla vendita, con l\'inizio di Luca che azzera la fermata: la parte della fermata è € 0,00, e l\'avviso dice chi l\'ha azzerata; seguito, le righe sommano zero', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);
    $this->travel(1)->minutes();
    // L'inizio di Luca il 1/11, il giorno della vendita: i giorni dal 1/11 li rimborsa lui, e della fermata non resta niente (€ 60,16 → € 0,00).
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    expect(collect(\App\Models\Gestionale\Subentro::find($inizio->id)->registro['fermata_ridotta'] ?? [])->map(fn ($f) => [(int) $f['prima'], (int) $f['residuo']])->all())->toBe([[6016, 0]]);
    $this->travel(1)->minutes();
    $atteso = SL53K_FERMATA . '; la parte della fermata di questo passaggio è € 0,00: l\'inizio della locazione di Luca del 1 novembre 2026 l\'ha azzerata. L\'annullamento del conguaglio non li cambia: correggi dal Wallet i saldi che si modificano.';

    expect(fds50Storico($s, $s['vendita']->fresh())['conguaglio']['avvisi'] ?? [])->toBe([$atteso]);
    $r = sl53AnnullaConguaglio($this, $s, $s['vendita']);
    expect(sl53Errori($r))->toBe([])
        ->and(sl53Messaggio($r)['message'])->toEndWith(' ' . $atteso);

    // Seguito dalle rotte: la parte della fermata è zero, e le due righe si correggono dal Wallet senza rifiuti.
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => 0, 'Elsa' => 0], $atteso))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 0, 'Ugo' => 0]);
});

it('le correzioni legate in un piano che non si riscrive più: l\'avviso dice che non si modificano più e di registrare la differenza; seguito, le righe sommano zero', function () {
    [$s, $vendita] = sl53kCorrezioneLegata($this, terzoPiano: true);

    $atteso = SL53K_CORREZIONE . ' Non si modificano più quelli nel piano «Rate di dicembre», che non si riscrive più (€ 0,77 a credito di Elsa, € 0,77 a debito di Ugo). L\'annullamento del conguaglio non li cambia: registra dal Wallet la differenza con un saldo legato alla vendita del 1 settembre 2026.';
    expect(fds50Storico($s, $vendita->fresh())['conguaglio']['avvisi'] ?? null)->toBe([$atteso]);
    expect(sl53Errori(sl53AnnullaConguaglio($this, $s, $vendita)))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $vendita, ['Elsa' => 0, 'Ugo' => 0], $atteso))->toBe([]);
    expect(sl53Legate($s, $vendita))->toBe(['Elsa' => 0, 'Ugo' => 0]);
});

it('una correzione legata sola, libera: l\'avviso la dice al singolare', function () {
    [$s, $vendita] = sl53kCorrezioneLegata($this, senza: ['Ugo']);

    expect(fds50Storico($s, $vendita->fresh())['conguaglio']['avvisi'] ?? null)->toBe([SL53K_APRE . 'Il saldo del Wallet legato a questo passaggio sulla gestione Ordinaria 2026 è oggi di € 0,77 a credito di Elsa; la parte da regolare a mano con questo passaggio è € 0,00: non ha una fermata su questa gestione. L\'annullamento del conguaglio non lo cambia: correggilo dal Wallet.']);
});

it('una correzione legata in un piano che non si riscrive più e l\'altra libera: l\'avviso nomina quella ferma e dice le due strade; seguito, le righe sommano zero', function () {
    [$s, $vendita] = sl53kCorrezioneLegata($this, terzoPiano: true, dopoIlTerzo: ['Ugo']);

    $atteso = SL53K_CORREZIONE . ' Non si modifica più quello nel piano «Rate di dicembre», che non si riscrive più (€ 0,77 a credito di Elsa). L\'annullamento del conguaglio non li cambia: correggi dal Wallet i saldi che si modificano, e per chi non ne ha uno registra la differenza con un saldo legato alla vendita del 1 settembre 2026.';
    expect(fds50Storico($s, $vendita->fresh())['conguaglio']['avvisi'] ?? null)->toBe([$atteso]);
    expect(sl53Errori(sl53AnnullaConguaglio($this, $s, $vendita)))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $vendita, ['Elsa' => 0, 'Ugo' => 0], $atteso))->toBe([]);
    expect(sl53Legate($s, $vendita))->toBe(['Elsa' => 0, 'Ugo' => 0]);
});

it('la fine «Scrivi» con un saldo legato a lei e la vendita fermata sulla sua coppia: gli avvisi dicono la fermata e, dopo, i fatti del saldo legato alla fine', function () {
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Ines', -1000, (int) $s['fine']->id)))->toBe([]);

    $avvisi = FermataDopoScrivi::avvisiDelConguaglio($s['fine']->fresh());

    expect($avvisi)->toHaveCount(2)
        ->and($avvisi[0])->toStartWith('La vendita del 1 novembre 2026 si è fermata su questo conguaglio')
        ->and($avvisi[1])->toBe(SL53K_APRE . 'Il saldo del Wallet legato a questo passaggio sulla gestione Ordinaria 2026 è oggi di € 10,00 a credito di Ines; la parte da regolare a mano con questo passaggio è € 0,00: non ha una fermata su questa gestione. L\'annullamento del conguaglio non lo cambia: correggilo dal Wallet.');
});

/** L'esercizio 2026 si chiude e si apre il 2027, con la stessa gestione: i saldi dell'unità mostrano solo l'esercizio aperto. */
function sl53kChiudiIl2026($test, array $s): \App\Models\Esercizio
{
    $s['e']->update(['stato' => 'chiuso']);
    $nuovo = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($nuovo, $s['g']->id);

    return $nuovo;
}

/** Gli id delle righe che la pagina dei saldi del condominio mostra. @return list<int> */
function sl53kNelWallet($test, array $s): array
{
    $props = $test->actingAs($test->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];

    return collect($props['immobili'])->flatMap(fn ($u) => $u['saldi'])->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
}

it('le righe della fermata in un esercizio che non è quello aperto, già la parte della fermata: l\'avviso lo dice, e non propone niente', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);
    $this->travel(1)->minutes();
    $ids = Saldo::where('passaggio_id', $s['vendita']->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    sl53kChiudiIl2026($this, $s);
    expect(array_intersect($ids, sl53kNelWallet($this, $s)))->toBe([]);

    $atteso = SL53K_FERMATA . ': sono già la parte della fermata di questo passaggio.';
    expect(fds50Storico($s, $s['vendita']->fresh())['conguaglio']['avvisi'] ?? null)->toBe([$atteso]);
    $r = sl53AnnullaConguaglio($this, $s, $s['vendita']);
    expect(sl53Errori($r))->toBe([])
        ->and(sl53Messaggio($r)['message'])->toEndWith(' ' . $atteso);
});

it('le correzioni legate libere in un esercizio che non è quello aperto: l\'avviso dice che non si modificano più e di registrare la differenza nell\'esercizio aperto; seguito, le righe sommano zero', function () {
    [$s, $vendita] = sl53kCorrezioneLegata($this);
    $ids = Saldo::where('passaggio_id', $vendita->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    sl53kChiudiIl2026($this, $s);
    expect(array_intersect($ids, sl53kNelWallet($this, $s)))->toBe([]);

    $atteso = SL53K_CORREZIONE . ' Non si modificano più quelli nell\'esercizio «Esercizio 2026», che è chiuso (€ 0,77 a credito di Elsa, € 0,77 a debito di Ugo). L\'annullamento del conguaglio non li cambia: registra dal Wallet la differenza con un saldo legato alla vendita del 1 settembre 2026.';
    expect(fds50Storico($s, $vendita->fresh())['conguaglio']['avvisi'] ?? null)->toBe([$atteso]);
    expect(sl53Errori(sl53AnnullaConguaglio($this, $s, $vendita)))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $vendita, ['Elsa' => 0, 'Ugo' => 0], $atteso))->toBe([]);
    expect(sl53Legate($s, $vendita))->toBe(['Elsa' => 0, 'Ugo' => 0]);
});

it('una riga legata nell\'esercizio aperto e una in quello chiuso: l\'avviso dice quanto sommano, la parte della fermata, quella che non si modifica più e le due strade; seguito, le righe sommano la parte', function () {
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 1000, (int) $s['vendita']->id)))->toBe([]);
    $this->travel(1)->minutes();
    sl53kChiudiIl2026($this, $s);
    // Nel 2027 la porta accetta un'altra riga legata alla vendita per Elsa: il doppione si guarda nell'esercizio aperto.
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', -400, (int) $s['vendita']->id)))->toBe([]);

    $atteso = SL53K_APRE . 'I saldi del Wallet legati a questo passaggio sulla gestione Ordinaria 2026 sommano oggi € 6,00 a debito di Elsa, e Ugo non ne ha; la parte della fermata di questo passaggio è € 60,16 a debito di Elsa e € 60,16 a credito di Ugo. Non si modifica più quello nell\'esercizio «Esercizio 2026», che è chiuso (€ 10,00 a debito di Elsa). L\'annullamento del conguaglio non li cambia: correggi dal Wallet i saldi che si modificano, e per chi non ne ha uno registra la differenza con un saldo legato alla vendita del 1 novembre 2026.';
    expect(fds50Storico($s, $s['vendita']->fresh())['conguaglio']['avvisi'] ?? null)->toBe([$atteso]);
    // Conto a mano: la riga di Elsa del 2027 −400 + 6016 − 600 = 5016; Ugo −6016 dalla porta.
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Elsa' => 6016, 'Ugo' => -6016], $atteso))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6016, 'Ugo' => -6016]);
});

it('le correzioni legate in un piano che non si riscrive più, in un esercizio che non è quello aperto: l\'avviso dice dove sono e di registrare la differenza; seguito, le righe sommano zero', function () {
    [$s, $vendita] = sl53kCorrezioneLegata($this, terzoPiano: true);
    sl53kChiudiIl2026($this, $s);

    $atteso = SL53K_CORREZIONE . ' Non si modificano più quelli nel piano «Rate di dicembre», che non si riscrive più (€ 0,77 a credito di Elsa, € 0,77 a debito di Ugo). L\'annullamento del conguaglio non li cambia: registra dal Wallet la differenza con un saldo legato alla vendita del 1 settembre 2026.';
    expect(fds50Storico($s, $vendita->fresh())['conguaglio']['avvisi'] ?? null)->toBe([$atteso]);
    $r = sl53AnnullaConguaglio($this, $s, $vendita);
    expect(sl53Errori($r))->toBe([])
        ->and(sl53Messaggio($r)['message'])->toEndWith(' ' . $atteso);
    expect(sl53SeguiIFatti($this, $s, $vendita, ['Elsa' => 0, 'Ugo' => 0], $atteso))->toBe([]);
    expect(sl53Legate($s, $vendita))->toBe(['Elsa' => 0, 'Ugo' => 0]);
});

it('le righe della fermata nell\'esercizio chiuso, con un passaggio registrato dopo, le rate emesse o un piano in bozza che le ha assorbite: sono già la parte della fermata, e l\'avviso non indica nessun annullamento', function (string $caso) {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);
    $this->travel(1)->minutes();
    if ($caso !== 'rate emesse') {
        fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Anna', '2026-12-15'));
        $this->travel(1)->minutes();
    }
    match ($caso) {
        'rate emesse', 'passaggio dopo e rate emesse' => fds50Emetti($this, $s, '2026-12-31'),
        'piano in bozza' => sl53SecondoPiano($s),
        default => null,
    };
    $this->travel(1)->minutes();
    sl53kChiudiIl2026($this, $s);

    $avvisi = fds50Storico($s, $s['vendita']->fresh())['conguaglio']['avvisi'] ?? [];
    expect($avvisi)->toBe([SL53K_FERMATA . ': sono già la parte della fermata di questo passaggio.'])
        ->and(implode(' ', $avvisi))->not->toContain('annullando');
})->with([['passaggio dopo'], ['rate emesse'], ['passaggio dopo e rate emesse'], ['piano in bozza']]);

it('senza righe legate «Annulla il conguaglio» non aggiunge niente', function () {
    $s = sl53Scenario($this);

    expect(fds50Storico($s, $s['vendita']->fresh())['conguaglio']['avvisi'] ?? null)->toBe([]);
    $r = sl53AnnullaConguaglio($this, $s, $s['vendita']);
    expect(sl53Messaggio($r)['type'])->toBe('success')
        ->and(sl53Messaggio($r)['message'])->not->toContain('legat');
});

it('le righe legate alla vendita di Anna sono la parte della sua fermata, ma le fermate sono di più passaggi sulle stesse persone: la parte non si legge da sola, e l\'avviso non ordina di correggere senza condizione; seguito, le righe si correggono senza rifiuti', function (int $cents) {
    // Ugo e Anna al 50 % vendono a Elsa il 12/3 e il 1/4 (le fermate sono € 153,33 e € 142,94): una voce sola, la parte di Anna non si legge da sola.
    $s = b51aDl7($this, ['titolari' => [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]], 'senzaLuca' => true]);
    b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-03-12', 50);
    $s['vendita_anna'] = b51aVendi($this, $s, 'Anna', 'Elsa', '2026-04-01', 50);
    foreach ([['Anna', -$cents], ['Elsa', $cents]] as [$chi, $riga]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $riga, (int) $s['vendita_anna']->id)))->toBe([]);
    }
    $this->travel(1)->minutes();

    $euro = \App\Helpers\MoneyHelper::format($cents);
    $atteso = SL53K_APRE . sprintf('I saldi del Wallet legati a questo passaggio sulla gestione Ordinaria 2026 sommano oggi %s a credito di Anna e %s a debito di Elsa. ', $euro, $euro)
        . 'L\'annullamento del conguaglio non li cambia: se non sono la parte della fermata di questo passaggio, correggi dal Wallet i saldi che si modificano.';
    expect(fds50Storico($s, $s['vendita_anna']->fresh())['conguaglio']['avvisi'] ?? null)->toBe([$atteso]);

    $r = sl53AnnullaConguaglio($this, $s, $s['vendita_anna']);
    expect(sl53Errori($r))->toBe([])
        ->and(sl53Messaggio($r)['message'])->toEndWith(' ' . $atteso);
    // Seguito, se non erano la parte: le righe vanno dove le porta chi le corregge, dal Wallet, senza rifiuti.
    expect(sl53SeguiIFatti($this, $s, $s['vendita_anna'], ['Anna' => -2000, 'Elsa' => 2000], $atteso))->toBe([]);
    expect(sl53Legate($s, $s['vendita_anna']))->toBe(['Anna' => -2000, 'Elsa' => 2000]);
})->with([14294, 5000]);

it('le righe sulla gestione del box di una vendita che ha una fermata solo sull\'unità: la parte da regolare a mano è € 0,00, e la frase dice che non ha una fermata su questa gestione e su questa unità', function () {
    $s = sl53Scenario($this, box: true);
    sl53DueLegate($this, $s);
    foreach ([['Ugo', -500], ['Elsa', 500]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id, ['immobile_id' => $s['box']->id])))->toBe([]);
    }
    $this->travel(1)->minutes();

    $atteso = SL53K_APRE . 'I saldi del Wallet legati a questo passaggio sulla gestione Ordinaria 2026 (Interno 1) sommano oggi € 60,16 a credito di Ugo e € 60,16 a debito di Elsa: sono già la parte della fermata di questo passaggio. '
        . 'I saldi del Wallet legati a questo passaggio sulla gestione Ordinaria 2026 (Box 1) sommano oggi € 5,00 a credito di Ugo e € 5,00 a debito di Elsa; la parte da regolare a mano con questo passaggio è € 0,00: non ha una fermata su questa gestione e su questa unità. '
        . 'L\'annullamento del conguaglio non li cambia: correggi dal Wallet i saldi che si modificano.';
    expect(fds50Storico($s, $s['vendita']->fresh())['conguaglio']['avvisi'] ?? null)->toBe([$atteso]);
});

it('le righe legate solo sul box di una vendita con una pertinenza: la frase dice anche l\'unità, perché il passaggio ne ha più d\'una, e la strada si percorre', function () {
    $s = sl53Scenario($this, box: true);
    foreach ([['Ugo', -500], ['Elsa', 500]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id, ['immobile_id' => $s['box']->id])))->toBe([]);
    }
    $this->travel(1)->minutes();

    $atteso = SL53K_APRE . 'I saldi del Wallet legati a questo passaggio sulla gestione Ordinaria 2026 (Box 1) sommano oggi € 5,00 a credito di Ugo e € 5,00 a debito di Elsa; '
        . 'la parte da regolare a mano con questo passaggio è € 0,00: non ha una fermata su questa gestione e su questa unità. '
        . 'L\'annullamento del conguaglio non li cambia: correggi dal Wallet i saldi che si modificano.';
    expect(fds50Storico($s, $s['vendita']->fresh())['conguaglio']['avvisi'] ?? null)->toBe([$atteso]);

    // Seguito: le due righe del box vanno a zero dal Wallet, senza rifiuti.
    foreach (Saldo::where('passaggio_id', $s['vendita']->id)->where('immobile_id', $s['box']->id)->get() as $riga) {
        expect(sl53Errori($this->actingAs($this->user)->from('/saldi')->put(route('admin.gestionale.saldi.update', [$s['c'], $riga]), ['saldo_iniziale' => 0, 'gestione_id' => $s['g']->id])))->toBe([]);
    }
    expect(Saldo::where('passaggio_id', $s['vendita']->id)->where('immobile_id', $s['box']->id)->sum('saldo_iniziale'))->toBe(0);
});
