<?php

/**
 * 1.11.0-beta.53 (Coda 244) — annullare un passaggio con le righe del Wallet legate a lui.
 *
 * Le righe legate libere si tolgono con il passaggio, e il registro dell'annullamento le conserva (`annullamento.saldi_legati_tolti`: id,
 * persona, gestione, unità, esercizio, cifra). Una riga legata già assorbita da un piano ferma l'annullamento come la coppia, con le stesse
 * strade: un piano che non ha emesso nulla si riporta in bozza e si elimina, e il lucchetto si riapre; uno che ha emesso va prima riaperto.
 * Le righe legate a un altro passaggio della stessa unità restano. Prima di confermare, e nel messaggio, la frase dice le righe che si
 * tolgono, con il verso a parole («a credito di», «a debito di»).
 *
 * «Annulla il conguaglio» della fine non tocca le righe legate alla vendita che si era fermata su quella coppia: restano, e l'avviso ne dice i
 * fatti (quanto sommano oggi, e che la parte della fermata della vendita, senza il conguaglio, è zero). La coppia della vendita, lo storico e la
 * nota non contano le righe legate.
 *
 * Annullare un inizio che ha ridotto o azzerato una fermata, o la fine di un conduttore che ne ha fatto risalire la parte: la parte torna
 * quella di prima. L'avviso dice, per ogni persona, quanto sommano oggi le righe legate ai passaggi della fermata e la parte che torna: se le
 * righe sono già quella parte lo dice, altrimenti (altra cifra, verso rovesciato, una gamba sola) dice il passo dopo dal Wallet, seguito
 * dalle rotte.
 *
 * Le cifre: vendita del 1/11, fermata € 60,16 a carico di Ugo verso Elsa; con il riporto di Ugo (+12345) i netti con le due righe sono Elsa
 * 20054, Ines 17852, Ugo 94439, totale 132345. Annullata la vendita e tolte le righe: Ines 17852, Ugo 114493 (le quote di Elsa tornano a
 * Ugo), totale 132345.
 *
 * Che cosa NON copre: due annullamenti contemporanei (sqlite non mostra i blocchi); la pagina dello storico (i suoi test leggono `effetti` e
 * `avvisi` che il server manda); i saldi a mano di prima, non legati (in `Beta53SaldiAManoDiPrimaTest`); più persone a carico della stessa
 * fermata (si guarda solo la prima); MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

const SL53A_TOLTE_PRIMA = 'I saldi del Wallet legati a questo passaggio si tolgono: € 60,16 a credito di Ugo e € 60,16 a debito di Elsa (gestione Ordinaria 2026).';
const SL53A_TOLTE_DOPO = 'I saldi del Wallet legati a questo passaggio sono stati tolti: € 60,16 a credito di Ugo e € 60,16 a debito di Elsa (gestione Ordinaria 2026).';
const SL53A_APERTURA = 'Un saldo del Wallet legato a questo passaggio è già stato assorbito da un piano rate, e il passaggio non si annulla così com\'è.';

/** Lo scenario con le due righe legate alla vendita; con `$riporto` Ugo ha anche il suo riporto di € 123,45, scritto prima di tutto. */
function sl53aConLeRighe($test, array $vendita = [], bool $riporto = false): array
{
    $s = sl53Scenario($test, $riporto ? ['Ugo' => 12345] : [], $vendita);
    sl53DueLegate($test, $s);

    return $s;
}

/** La vendita con la rinuncia alla sua coppia: così il piano dopo assorbe le righe legate e non una coppia della vendita (che fermerebbe prima). */
const SL53A_RINUNCIA = ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro il conguaglio della vendita'];

it('annullando la vendita le righe legate libere si tolgono, i netti tornano all\'oracolo e il registro le conserva', function () {
    $s = sl53aConLeRighe($this, riporto: true);
    expect(fds50Netti($s))->toBe(['Elsa' => 20054, 'Ines' => 17852, 'Ugo' => 94439]);
    $ids = collect(sl53Righe($s))->filter(fn ($x) => $x[4] === (int) $s['vendita']->id)->pluck(0)->sort()->values()->all();
    expect($ids)->toHaveCount(2);

    $r = sl53Annulla($this, $s, $s['vendita']);

    expect(sl53Errori($r))->toBe([])
        ->and(sl53Legate($s, $s['vendita']))->toBe([])
        ->and(Saldo::whereIn('id', $ids)->count())->toBe(0)
        ->and(fds50Netti($s))->toBe(['Ines' => 17852, 'Ugo' => 114493])
        ->and(array_sum(fds50Netti($s)))->toBe(132345);
    $tolti = Subentro::conAnnullati()->findOrFail($s['vendita']->id)->registro['annullamento']['saldi_legati_tolti'] ?? null;
    expect($tolti)->toBeArray()->toHaveCount(2);
    expect(collect($tolti)->map(fn ($x) => [(int) $x['id'], (int) $x['anagrafica_id'], (int) $x['gestione_id'], (int) $x['immobile_id'], (int) $x['esercizio_id'], (int) $x['saldo_iniziale']])->sortBy(0)->values()->all())
        ->toBe([
            [$ids[0], (int) $s['persone']['Ugo']->id, (int) $s['g']->id, (int) $s['unita']->id, (int) $s['e']->id, -6016],
            [$ids[1], (int) $s['persone']['Elsa']->id, (int) $s['g']->id, (int) $s['unita']->id, (int) $s['e']->id, 6016],
        ]);
});

it('prima di confermare gli effetti dicono le righe legate che si tolgono, e dopo il messaggio dice che sono state tolte', function () {
    $s = sl53aConLeRighe($this);

    $prima = fds50Storico($s, $s['vendita'])['annullabile'] ?? [];
    expect($prima['si'] ?? null)->toBeTrue()
        ->and(in_array(SL53A_TOLTE_PRIMA, $prima['effetti'] ?? [], true))->toBeTrue('Gli effetti non dicono le righe legate: ' . implode(' | ', $prima['effetti'] ?? []));

    $messaggio = sl53Messaggio(sl53Annulla($this, $s, $s['vendita']))['message'];
    expect(str_contains($messaggio, SL53A_TOLTE_DOPO))->toBeTrue('Il messaggio non dice le righe legate tolte: ' . $messaggio);
});

it('le righe legate che l\'annullamento toglie non si nominano fra i saldi a mano non legati', function () {
    // La ricerca dei saldi a mano scritti dopo il passaggio non prende le righe legate: le dice già l'effetto.
    $s = sl53aConLeRighe($this);

    $prima = fds50Storico($s, $s['vendita'])['annullabile']['avvisi'] ?? [];
    $messaggio = sl53Messaggio(sl53Annulla($this, $s, $s['vendita']))['message'];

    expect(collect($prima)->contains(fn ($t) => str_contains((string) $t, 'non legati a nessun passaggio')))->toBeFalse(implode(' | ', $prima))
        ->and(str_contains($messaggio, 'non legati a nessun passaggio'))->toBeFalse($messaggio)
        ->and(str_contains($messaggio, SL53A_TOLTE_DOPO))->toBeTrue('Il messaggio non dice le righe legate tolte: ' . $messaggio);
});

it('controprova — una riga legata alla fine di Ines, sulla stessa unità, resta quando si annulla la vendita (si toglie solo ciò che è legato al passaggio annullato)', function () {
    // Rossa finché il legame non c'è (la riga della fine non si riconosce); poi fissa che l'annullamento non tolga le righe legate di tutta l'unità.
    $s = sl53aConLeRighe($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Ines', -1000, (int) $s['fine']->id)))->toBe([]);
    expect(sl53Legate($s, $s['fine']))->toBe(['Ines' => -1000]);

    sl53Annulla($this, $s, $s['vendita']);

    expect(sl53Legate($s, $s['vendita']))->toBe([])
        ->and(sl53Legate($s, $s['fine']))->toBe(['Ines' => -1000]);
});

it('una gamba sola legata si toglie lo stesso: l\'annullamento non chiede che le righe legate sommino zero', function () {
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6016]);

    $r = sl53Annulla($this, $s, $s['vendita']);

    expect(sl53Errori($r))->toBe([])
        ->and(Subentro::conAnnullati()->findOrFail($s['vendita']->id)->annullato())->toBeTrue()
        ->and(Saldo::where('anagrafica_id', $s['persone']['Elsa']->id)->where('saldo_iniziale', 6016)->count())->toBe(0);
});

it('un saldo legato assorbito da un piano che non ha emesso nulla: il passaggio non si annulla, la frase dice la strada, e niente cambia', function () {
    $s = sl53aConLeRighe($this, SL53A_RINUNCIA);
    expect(fds50Coppia($s, $s['vendita']))->toBe([]);
    $piano = sl53SecondoPiano($s);
    $prima = sl53Righe($s);

    $r = sl53Annulla($this, $s, $s['vendita']);
    $errore = (string) (sl53Errori($r)['passaggio'][0] ?? '');

    expect($errore)->toStartWith(SL53A_APERTURA)
        ->and(str_contains($errore, 'Il piano «Conguaglio 2026» non ha ancora emesso nulla: se è approvato riportalo in bozza, elimina il piano (il lucchetto si riapre) e torna qui; poi rifai il piano.'))->toBeTrue($errore)
        ->and(Subentro::conAnnullati()->findOrFail($s['vendita']->id)->annullato())->toBeFalse()
        ->and(sl53Righe($s))->toBe($prima);
    // Lo storico lo dice prima: il passaggio non è annullabile, con lo stesso motivo.
    expect(fds50Storico($s, $s['vendita'])['annullabile']['motivo'] ?? '')->toBe($errore);
    expect((int) $piano->id)->toBeGreaterThan(0);
});

it('la strada d\'uscita: eliminato il piano (il lucchetto si riapre), il passaggio si annulla e le righe legate si tolgono', function () {
    $s = sl53aConLeRighe($this, SL53A_RINUNCIA);
    $piano = sl53SecondoPiano($s);
    expect(collect(sl53Righe($s))->filter(fn ($x) => $x[4] === (int) $s['vendita']->id)->every(fn ($x) => $x[5] === true && $x[6] === (int) $piano->id))->toBeTrue(json_encode(sl53Righe($s)));

    $this->actingAs($this->user)->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $piano]));
    expect(\App\Models\Gestionale\PianoRate::find($piano->id))->toBeNull();

    $r = sl53Annulla($this, $s, $s['vendita']);

    expect(sl53Errori($r))->toBe([])
        ->and(Subentro::conAnnullati()->findOrFail($s['vendita']->id)->annullato())->toBeTrue()
        ->and(collect(sl53Righe($s))->filter(fn ($x) => $x[3] === null && in_array($x[2], [6016, -6016], true))->values()->all())->toBe([]);
});

it('un saldo legato assorbito da un piano che ha emesso: rifiuto con «va prima riaperto», e la strada breve dice i saldi legati, non la coppia', function () {
    $s = sl53aConLeRighe($this, SL53A_RINUNCIA);
    $piano = sl53SecondoPiano($s, stato: 'approvato');
    fds50Emetti($this, $s, '2026-11-30', $piano);

    $errore = (string) (sl53Errori(sl53Annulla($this, $s, $s['vendita']))['passaggio'][0] ?? '');

    expect($errore)->toStartWith(SL53A_APERTURA)
        ->and(str_contains($errore, 'Il passaggio può restare: il piano «Conguaglio 2026» si emette così com\'è, con i saldi legati a questo passaggio già dentro.'))->toBeTrue($errore)
        ->and(str_contains($errore, 'va prima riaperto'))->toBeTrue($errore)
        ->and(str_contains($errore, 'con la coppia già dentro'))->toBeFalse($errore)
        ->and(Subentro::conAnnullati()->findOrFail($s['vendita']->id)->annullato())->toBeFalse()
        ->and(count(sl53Legate($s, $s['vendita'])))->toBe(2);
});

it('dopo la vendita un altro passaggio sulla stessa unità: la vendita non si annulla prima; annullato l\'altro, si annulla e toglie le sue righe legate', function () {
    $s = sl53aConLeRighe($this);
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01'))['subentro'];

    $rifiuto = (string) (sl53Errori(sl53Annulla($this, $s, $s['vendita']))['passaggio'][0] ?? '');
    expect($rifiuto)->toStartWith('Dopo questo, su Interno 1, c\'è un altro passaggio');

    expect(sl53Messaggio(sl53Annulla($this, $s, $inizio, 'Contratto mai firmato, annullo l\'inizio'))['message'])->toStartWith('Passaggio annullato.');
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6016, 'Ugo' => -6016]);

    expect(sl53Errori(sl53Annulla($this, $s, $s['vendita'])))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe([])
        ->and(collect(sl53Righe($s))->filter(fn ($x) => $x[3] === null && in_array($x[2], [6016, -6016], true))->values()->all())->toBe([]);
});

it('«Annulla il conguaglio» della fine: le righe legate alla vendita restano, e l\'avviso ne dice i fatti prima di confermare; seguito, le righe sommano zero', function () {
    $s = sl53aConLeRighe($this);

    $avvisi = fds50Storico($s, $s['fine'])['conguaglio']['avvisi'] ?? [];
    $avviso = (string) collect($avvisi)->first(fn (string $t) => str_starts_with($t, 'La vendita del 1 novembre 2026 si è fermata'));
    expect($avviso)->toBe('La vendita del 1 novembre 2026 si è fermata su questo conguaglio: € 60,16 dei giorni dal 1 novembre 2026 sono rimasti a carico di Ugo sulla gestione Ordinaria 2026, da regolare a mano con Elsa. Annullato il conguaglio, quella fermata non vale più. I saldi del Wallet legati alla vendita del 1 novembre 2026 sommano oggi € 60,16 a credito di Ugo e € 60,16 a debito di Elsa; la parte della sua fermata è € 0,00. L\'annullamento del conguaglio non li cambia: correggi dal Wallet i saldi che si modificano.');

    sl53AnnullaConguaglio($this, $s, $s['fine']);

    expect(Subentro::findOrFail($s['fine']->id)->conguaglioAnnullato())->toBeTrue()
        ->and(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6016, 'Ugo' => -6016]);
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => 0, 'Elsa' => 0], $avviso))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 0, 'Ugo' => 0]);
});

it('annullare la fine del conduttore che fa risalire la parte di una fermata dice quanto sommano i saldi legati alla vendita e la parte che torna; seguito, le righe sommano € 30,58', function () {
    // Il caso INT (`Beta51PassaggiParteCheRisaleTest`): la vendita del 1/10 si ferma su € 90,74 verso Elsa, l'inizio di Luca del 1/11 la riduce,
    // la fine di Luca del 1/12 la fa risalire da € 30,58 a € 61,15. Le righe legate alla vendita: Ugo −9074, Elsa +9074.
    $s = b51aInt($this);
    foreach ([['Ugo', -9074], ['Elsa', 9074]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id)))->toBe([]);
    }
    $fine = fds50Registra($this, $s, b51aIntFine($s, '2026-12-01'))['subentro'];
    expect(b51aRegistro($fine)['fermata_risale'] ?? [])->toHaveCount(1);

    $avviso = (string) collect(fds50Storico($s, $fine)['annullabile']['avvisi'] ?? [])->first(fn ($t) => str_contains($t, 'è risalita da'));

    expect($avviso)->toEndWith(' Se le parti hanno regolato la differenza fra loro, quell\'accordo va rivisto. I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 90,74 a credito di Ugo e € 90,74 a debito di Elsa; la parte che torna è € 30,58 a credito di Ugo e € 30,58 a debito di Elsa. Annullare il passaggio non li cambia: correggi dal Wallet i saldi che si modificano.');
    expect(sl53Errori(sl53Annulla($this, $s, $fine, 'Registrata per sbaglio, la locazione continua')))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -3058, 'Elsa' => 3058], $avviso))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
});

it('controprova — la coppia della vendita, lo storico e il suo stato non contano le righe legate (verde anche prima: fissa che la colonna nuova non li cambi)', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);

    $voce = fds50Storico($s, $s['vendita']);
    expect(fds50Coppia($s, $s['vendita']))->toBe(['Elsa' => 38, 'Ugo' => -38])
        ->and((int) ($voce['conguaglio']['importo'] ?? -1))->toBe(38)
        ->and($voce['conguaglio']['stato'] ?? null)->toBe('proposto');
});

it('nessuna riga legata resta su un passaggio annullato: prima due righe legate alla vendita, dopo l\'annullamento nessuna legata a un passaggio annullato', function () {
    $s = sl53aConLeRighe($this);
    expect(count(sl53Legate($s, $s['vendita'])))->toBe(2);

    sl53Annulla($this, $s, $s['vendita']);

    $annullati = Subentro::conAnnullati()->whereNotNull('annullato_il')->pluck('id')->map(fn ($id) => (int) $id)->all();
    expect($annullati)->toContain((int) $s['vendita']->id);
    expect(Saldo::all()->filter(fn (Saldo $x) => $x->passaggio_id !== null && in_array((int) $x->passaggio_id, $annullati, true))->values()->all())->toBe([]);
});

// --- Le righe legate che sono già la parte che torna -----------------------------------------------------------------------------

/** O-DL7-DUEPASSI: la fine di Luca del 18/4 ferma € 268,20 verso Elsa, con le righe legate date; l'inizio di Marco lo stesso giorno la azzera. */
function sl53aDuePassi($test, array $righe): array
{
    $s = b51aDl7ConVendita($test);
    $fine = b51aRegistraFine($test, $s, 'Luca', '2026-04-18');
    foreach ($righe as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($test, $s, $chi, $cents, (int) $fine->id)))->toBe([]);
    }
    $marco = b51aRegistraInizio($test, $s, 'Marco', '2026-04-18');

    return [$s, (string) collect(fds50Storico($s, $marco)['annullabile']['avvisi'] ?? [])->first(fn ($t) => str_contains($t, 'è scesa da')), $fine, $marco];
}

it('annullare l\'inizio che aveva azzerato la fermata: le righe legate alla fine sono già la parte che torna, e l\'avviso lo dice', function () {
    [, $avviso] = sl53aDuePassi($this, [['Ugo', -26820], ['Elsa', 26820]]);

    expect($avviso)->toBe('Con questo inizio la parte verso Elsa della fermata della fine della locazione di Luca del 18 aprile 2026 è scesa da € 268,20 a € 0,00: annullandolo torna da € 0,00 a € 268,20. Se le parti hanno già regolato la differenza fra loro, quell\'accordo va rivisto. I saldi del Wallet legati alla fine della locazione di Luca del 18 aprile 2026 sommano oggi € 268,20 a credito di Ugo e € 268,20 a debito di Elsa: sono già la parte che torna.');
});

it('controprova — righe legate alla fine di altra cifra, con il verso rovesciato o con una gamba sola: non sono la parte che torna, e l\'avviso dice quanto sommano e il passo dopo; seguito, le righe sono la parte che torna', function (array $righe, string $coda) {
    [$s, $avviso, $fine, $marco] = sl53aDuePassi($this, $righe);

    expect($avviso)->toEndWith($coda)
        ->and(str_contains($avviso, 'sono già la parte che torna'))->toBeFalse($avviso);
    expect(sl53Errori(sl53Annulla($this, $s, $marco, 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $fine, ['Ugo' => -26820, 'Elsa' => 26820], $avviso))->toBe([]);
    expect(sl53Legate($s, $fine))->toBe(['Elsa' => 26820, 'Ugo' => -26820]);
})->with([
    'altra cifra' => [[['Ugo', -10000], ['Elsa', 10000]], ' I saldi del Wallet legati alla fine della locazione di Luca del 18 aprile 2026 sommano oggi € 100,00 a credito di Ugo e € 100,00 a debito di Elsa; la parte che torna è € 268,20 a credito di Ugo e € 268,20 a debito di Elsa. Annullare il passaggio non li cambia: correggi dal Wallet i saldi che si modificano.'],
    'verso rovesciato' => [[['Ugo', 26820], ['Elsa', -26820]], ' I saldi del Wallet legati alla fine della locazione di Luca del 18 aprile 2026 sommano oggi € 268,20 a debito di Ugo e € 268,20 a credito di Elsa; la parte che torna è € 268,20 a credito di Ugo e € 268,20 a debito di Elsa. Annullare il passaggio non li cambia: correggi dal Wallet i saldi che si modificano.'],
    'una gamba sola' => [[['Elsa', 26820]], ' Il saldo del Wallet legato alla fine della locazione di Luca del 18 aprile 2026 è oggi di € 268,20 a debito di Elsa, e Ugo non ne ha; la parte che torna è € 268,20 a debito di Elsa e € 268,20 a credito di Ugo. Annullare il passaggio non lo cambia: registra dal Wallet la differenza con un saldo legato alla fine della locazione di Luca del 18 aprile 2026.'],
]);

it('annullare la fine del conduttore che fa risalire la parte, con le righe legate alla vendita già sulla parte di prima (€ 30,58): sono già la parte che torna', function () {
    // Il caso INT: la vendita del 1/10 si ferma su € 90,74, l'inizio di Luca del 1/11 la riduce a € 30,58 e l'amministratore porta le righe
    // legate a quella cifra; la fine di Luca del 1/12 la fa risalire a € 61,15. Annullata la fine, la parte torna a € 30,58: le righe lo sono già.
    $s = b51aInt($this);
    foreach ([['Ugo', -3058], ['Elsa', 3058]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id)))->toBe([]);
    }
    $fine = fds50Registra($this, $s, b51aIntFine($s, '2026-12-01'))['subentro'];
    expect(b51aRegistro($fine)['fermata_risale'][0]['prima'] ?? null)->toBe(3058);

    $avviso = (string) collect(fds50Storico($s, $fine)['annullabile']['avvisi'] ?? [])->first(fn ($t) => str_contains($t, 'è risalita da'));

    expect(str_contains($avviso, 'annullandolo torna da € 61,15 a € 30,58. Se le parti hanno regolato la differenza fra loro, quell\'accordo va rivisto. I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 30,58 a credito di Ugo e € 30,58 a debito di Elsa: sono già la parte che torna.'))->toBeTrue($avviso)
        ->and(str_contains($avviso, 'dal Wallet i saldi'))->toBeFalse($avviso);
});

it('il passaggio a parole mette «ad» davanti a un nome che comincia per «a», e «a» davanti agli altri', function () {
    $passaggio = fn (string $tipo, ?string $esce, ?string $entra) => (new Subentro())->forceFill(['tipo_passaggio' => $tipo, 'registro' => ['nomi' => ['uscente' => $esce, 'entrante' => $entra]]]);

    expect(\App\Actions\Subentro\AnnullaPassaggioAction::descrivi($passaggio('vendita', 'Elsa', 'Anna')))->toBe('la vendita da Elsa ad Anna')
        ->and(\App\Actions\Subentro\AnnullaPassaggioAction::descrivi($passaggio('vendita', 'Anna', 'Àlvaro')))->toBe('la vendita da Anna ad Àlvaro')
        ->and(\App\Actions\Subentro\AnnullaPassaggioAction::descrivi($passaggio('inizio_locazione', null, 'Anna')))->toBe('l\'inizio locazione ad Anna')
        ->and(\App\Actions\Subentro\AnnullaPassaggioAction::descrivi($passaggio('vendita', 'Anna', 'Elsa')))->toBe('la vendita da Anna a Elsa')
        ->and(\App\Actions\Subentro\AnnullaPassaggioAction::descrivi($passaggio('fine_locazione', 'Anna', 'Ines')))->toBe('la fine locazione di Anna, con Ines al suo posto');
});
