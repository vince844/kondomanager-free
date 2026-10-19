<?php

/**
 * 1.11.0-beta.53 (Coda 244) — i casi della riga legata a un passaggio che gli altri test non costruiscono: la porta, la pagina dei saldi, la
 * persona che non si elimina, gli effetti e gli avvisi dell'annullamento, le frasi della fermata ridotta.
 *
 * La porta: un passaggio annullato fra la richiesta e la scrittura si rifiuta dentro la transazione; la gestione di un altro condominio si
 * rifiuta sul campo; la stessa persona può avere righe legate allo stesso passaggio su gestioni diverse e su unità diverse della famiglia (il
 * doppione è per passaggio, persona, gestione, unità ed esercizio). La pagina: sul box la vendita non ha una parte da regolare a mano, se la
 * fermata sta sull'appartamento. La persona di un passaggio senza saldi legati non riceve la strada del saldo legato.
 *
 * L'annullamento: una riga legata sola si dice al singolare, una riga legata a zero non si dice; i saldi scritti a mano dopo la registrazione
 * si nominano solo se sono saldi manuali di una persona che ha o ha avuto una riga sull'unità, al singolare se sono uno, con un avviso per
 * unità. Gli avvisi del conguaglio della fine dicono le righe legate della sua unità; una voce ridotta con più vendite nomina le righe
 * legate a ognuna; annullare l'inizio che aveva azzerato una fermata nomina le righe legate alla fine.
 *
 * Le cifre: la fermata della vendita del 1/11 è € 60,16 (6016), a carico di Ugo, verso Elsa (`Beta53SaldoLegatoScenari.php`); quella della fine
 * di Luca del 18/4 è € 268,20 (`Beta51PassaggiFineConduttoreTest`); le due vendite del 12/3 e del 1/4 si fermano su € 153,33 e € 142,94
 * (`Beta51PassaggiDueFermateTest`).
 *
 * Che cosa NON copre: due richieste davvero contemporanee su MySQL (la corsa si simula: il passaggio si annulla quando la transazione della
 * porta comincia); se il verbo di una frase sulle righe legate sia quello giusto per la storia del Wallet (la frase si guarda per le righe che
 * nomina; per gli avvisi dell'annullamento il verbo lo fissa `Beta53AnnullaConRigheLegateTest`); la pagina Vue; MySQL.
 */

use App\Models\Anagrafica;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Services\Subentro\FermataDopoScrivi;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

/** Il primo errore del campo dato, o stringa vuota. */
function sl53xErrore($r, string $campo): string
{
    return (string) (sl53Errori($r)[$campo][0] ?? '');
}

/** Le proprietà dell'unità data nella pagina dei saldi del condominio. */
function sl53xUnitaNellaPagina($test, array $s, int $immobileId): array
{
    $props = $test->actingAs($test->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    $unita = collect($props['immobili'])->firstWhere('id', $immobileId);
    expect($unita)->not->toBeNull();

    return $unita;
}

/** Un'altra gestione attiva del condominio, legata all'esercizio aperto. */
function sl53xAltraGestione(array $s): Gestione
{
    $g = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Straordinaria facciata', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'attiva' => true]);
    legaAEsercizio($s['e'], $g->id);

    return $g;
}

// --- La porta ---------------------------------------------------------------------------------------------------------------------

it('un passaggio annullato mentre la riga si scrive: la transazione della porta lo rilegge, rifiuta sul passaggio e non scrive niente', function () {
    $s = sl53Scenario($this);
    $prima = Saldo::count();
    // La richiesta trova il passaggio vivo; quando la transazione della porta comincia, un altro annullamento l'ha già chiuso.
    $annullato = false;
    Event::listen(TransactionBeginning::class, function () use (&$annullato, $s) {
        if ($annullato) {
            return;
        }
        $annullato = true;
        DB::table('subentri')->where('id', $s['vendita']->id)->orWhere('subentro_padre_id', $s['vendita']->id)->update(['annullato_il' => now()]);
    });

    $r = sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id);

    expect($annullato)->toBeTrue()
        ->and(sl53xErrore($r, 'passaggio_id'))->toBe('Il passaggio scelto non è di questo condominio, o è stato annullato.')
        ->and(Saldo::count())->toBe($prima);
});

it('una gestione attiva di un altro condominio: rifiuto sulla gestione e nessuna riga', function () {
    $s = sl53Scenario($this);
    $altro = sl53Scenario($this);
    $prima = Saldo::count();

    $r = sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id, ['gestione_id' => $altro['g']->id]);

    expect($r->status())->toBe(302)
        ->and(sl53xErrore($r, 'gestione_id'))->toBe('La gestione scelta non è fra quelle attive di questo condominio.')
        ->and(Saldo::count())->toBe($prima);
});

it('stessa persona, stesso passaggio, un\'altra gestione: la seconda riga legata non è un doppione', function () {
    $s = sl53Scenario($this);
    $altra = sl53xAltraGestione($s);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);

    $r = sl53Posta($this, $s, 'Elsa', 1000, (int) $s['vendita']->id, ['gestione_id' => $altra->id]);

    expect(sl53Errori($r))->toBe([])
        ->and(Saldo::where('anagrafica_id', $s['persone']['Elsa']->id)->whereNull('subentro_id')->orderBy('id')->get()
            ->map(fn (Saldo $x) => [(int) $x->gestione_id, (int) $x->saldo_iniziale, (int) $x->passaggio_id])->all())
        ->toBe([[(int) $s['g']->id, 6016, (int) $s['vendita']->id], [(int) $altra->id, 1000, (int) $s['vendita']->id]]);
});

it('stessa persona, stesso passaggio, l\'appartamento e il box passato con lui: la riga legata sul box non è un doppione', function () {
    $s = sl53Scenario($this, box: true);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);

    $r = sl53Posta($this, $s, 'Elsa', 1000, (int) $s['vendita']->id, ['immobile_id' => $s['box']->id]);

    expect(sl53Errori($r))->toBe([])
        ->and(Saldo::where('anagrafica_id', $s['persone']['Elsa']->id)->whereNull('subentro_id')->orderBy('id')->get()
            ->map(fn (Saldo $x) => [(int) $x->immobile_id, (int) $x->saldo_iniziale, (int) $x->passaggio_id])->all())
        ->toBe([[(int) $s['unita']->id, 6016, (int) $s['vendita']->id], [(int) $s['box']->id, 1000, (int) $s['vendita']->id]]);
});

// --- La pagina e la persona -------------------------------------------------------------------------------------------------------

it('la parte da regolare a mano è dell\'unità della fermata: sull\'appartamento la vendita ce l\'ha, sul box no', function () {
    $s = sl53Scenario($this, box: true);

    $suInterno = sl53xUnitaNellaPagina($this, $s, (int) $s['unita']->id)['passaggi_legabili'] ?? [];
    $sulBox = sl53xUnitaNellaPagina($this, $s, (int) $s['box']->id)['passaggi_legabili'] ?? [];

    expect(collect($suInterno)->firstWhere('id', (int) $s['vendita']->id)['con_parte_a_mano'] ?? null)->toBeTrue()
        ->and($sulBox)->toEqual([['id' => (int) $s['vendita']->id, 'parole' => 'la vendita del 1 novembre 2026', 'persone' => ['Ugo', 'Elsa'], 'con_parte_a_mano' => false]]);
});

it('la persona di un passaggio senza saldi legati non si elimina, e il messaggio non le indica di togliere un saldo legato', function () {
    $s = sl53Scenario($this);
    $ines = $s['persone']['Ines'];
    $ines->condomini()->detach();

    $r = $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $ines));

    expect(Anagrafica::find($ines->id))->not->toBeNull()
        ->and(sl53Messaggio($r)['type'])->toBe('error')
        ->and(sl53Messaggio($r)['message'])->toEndWith('perché lo storico delle unità, il conguaglio in saldi e i saldi legati a un passaggio la nominano.');
});

// --- Gli effetti dell'annullamento ------------------------------------------------------------------------------------------------

it('una riga legata sola si dice al singolare, prima e dopo; una riga legata portata a zero non si dice, e si toglie con le altre', function () {
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', -6016, (int) $s['vendita']->id)))->toBe([]);
    // Ugo e Elsa si accordano diversamente: la riga di Ugo si corregge a zero dal Wallet, e resta legata.
    $ugo = Saldo::where('anagrafica_id', $s['persone']['Ugo']->id)->where('saldo_iniziale', -6016)->firstOrFail();
    expect(sl53Errori($this->actingAs($this->user)->from('/saldi')->patch(route('admin.gestionale.saldi.update', [$s['c'], $ugo]), ['saldo_iniziale' => 0, 'gestione_id' => $s['g']->id])))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6016, 'Ugo' => 0]);

    $effetti = fds50Storico($s, $s['vendita'])['annullabile']['effetti'] ?? [];
    $messaggio = sl53Messaggio(sl53Annulla($this, $s, $s['vendita']))['message'];

    expect(in_array('Il saldo del Wallet legato a questo passaggio si toglie: € 60,16 a debito di Elsa (gestione Ordinaria 2026).', $effetti, true))->toBeTrue(implode(' | ', $effetti))
        ->and(str_contains($messaggio, 'Il saldo del Wallet legato a questo passaggio è stato tolto: € 60,16 a debito di Elsa (gestione Ordinaria 2026).'))->toBeTrue($messaggio)
        ->and(str_contains($messaggio, '€ 0,00'))->toBeFalse($messaggio)
        ->and(sl53Legate($s, $s['vendita']))->toBe([]);
});

// --- I saldi scritti a mano dopo la registrazione -----------------------------------------------------------------------------------

it('un saldo scritto dopo la vendita per una persona che non ha mai avuto righe sull\'unità non si nomina', function () {
    // Luca è nel condominio e non ha righe di titolarità da nessuna parte: il suo saldo non è di una persona del passaggio.
    $s = sl53Scenario($this);
    sl53SaldoDiPrima($s, 'Luca', 2500);

    $avvisi = fds50Storico($s, $s['vendita'])['annullabile']['avvisi'] ?? [];
    $m = sl53Messaggio(sl53Annulla($this, $s, $s['vendita']));

    expect($avvisi)->toBe([])
        ->and($m['type'])->toBe('success')
        ->and(str_contains($m['message'], 'Luca'))->toBeFalse($m['message']);
});

it('un saldo importato dopo la vendita non è scritto a mano dal Wallet, e non si nomina', function () {
    $s = sl53Scenario($this);
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['Elsa']->id, 'immobile_id' => $s['unita']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 4200, 'origine' => 'importato', 'is_applicato' => false]);

    $avvisi = fds50Storico($s, $s['vendita'])['annullabile']['avvisi'] ?? [];

    expect($avvisi)->toBe([]);
});

it('un saldo solo scritto a mano dopo la vendita: l\'avviso è al singolare', function () {
    $s = sl53Scenario($this);
    sl53SaldoDiPrima($s, 'Elsa', 1000);

    $avvisi = fds50Storico($s, $s['vendita'])['annullabile']['avvisi'] ?? [];

    expect($avvisi)->toBe(['Dalla registrazione di questo passaggio è stato scritto a mano dal Wallet, su Interno 1, un saldo non legato a nessun passaggio: € 10,00 a debito di Elsa (gestione Ordinaria 2026). Se era per questo passaggio, annullarlo non lo toglie: rivedilo dal Wallet, prima o dopo.']);
});

it('saldi scritti a mano sull\'appartamento e sul box: un avviso per unità, ognuno con le sue righe', function () {
    $s = sl53Scenario($this, box: true);
    sl53SaldoDiPrima($s, 'Elsa', 1000);
    sl53SaldoDiPrima($s, 'Elsa', 500, immobileId: (int) $s['box']->id);

    $avvisi = fds50Storico($s, $s['vendita'])['annullabile']['avvisi'] ?? [];

    expect($avvisi)->toBe([
        'Dalla registrazione di questo passaggio è stato scritto a mano dal Wallet, su Interno 1, un saldo non legato a nessun passaggio: € 10,00 a debito di Elsa (gestione Ordinaria 2026). Se era per questo passaggio, annullarlo non lo toglie: rivedilo dal Wallet, prima o dopo.',
        'Dalla registrazione di questo passaggio è stato scritto a mano dal Wallet, su Box 1, un saldo non legato a nessun passaggio: € 5,00 a debito di Elsa (gestione Ordinaria 2026). Se era per questo passaggio, annullarlo non lo toglie: rivedilo dal Wallet, prima o dopo.',
    ]);
});

// --- Gli avvisi e le frasi con le righe legate --------------------------------------------------------------------------------------

it('«Annulla il conguaglio» della fine dice i fatti delle righe legate alla vendita sulla sua unità, non di quella sul box', function () {
    $s = sl53Scenario($this, box: true);
    sl53DueLegate($this, $s);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 1000, (int) $s['vendita']->id, ['immobile_id' => $s['box']->id])))->toBe([]);

    $testo = implode(' | ', fds50Storico($s, $s['fine'])['conguaglio']['avvisi'] ?? []);

    expect(str_contains($testo, 'I saldi del Wallet legati alla vendita del 1 novembre 2026 sommano oggi € 60,16 a credito di Ugo e € 60,16 a debito di Elsa; la parte della sua fermata è € 0,00.'))->toBeTrue($testo)
        ->and(str_contains($testo, '€ 10,00'))->toBeFalse($testo);
});

it('una voce ridotta con due vendite: l\'inizio che la riduce nomina anche le righe legate alla seconda vendita', function () {
    // O-2F: Ugo e Anna al 50 % vendono a Elsa il 12/3 e il 1/4 (fermate € 153,33 e € 142,94); l'inizio di Marco del 1/6 le riduce in una voce
    // sola, con la prima vendita in `passaggio_id` e tutte e due in `passaggi_ids`. Le righe legate sono della vendita di Anna.
    $s = b51aDl7($this, ['titolari' => [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]], 'senzaLuca' => true]);
    $s['vendita_ugo'] = b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-03-12', 50);
    $s['vendita_anna'] = b51aVendi($this, $s, 'Anna', 'Elsa', '2026-04-01', 50);
    foreach ([['Anna', -14294], ['Elsa', 14294]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita_anna']->id)))->toBe([]);
    }

    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Marco', '2026-06-01'));
    $frase = (string) collect($a['rate']['frasi'])->first(fn ($t) => str_contains($t, 'resta da regolare') || str_contains($t, 'restano da regolare'));

    expect(str_contains($frase, 'Il saldo del Wallet legato') || str_contains($frase, 'I saldi del Wallet legati'))->toBeTrue($frase)
        ->and(str_contains($frase, 'alla vendita del 1 aprile 2026'))->toBeTrue($frase)
        ->and(str_contains($frase, '€ 142,94 a credito di Anna'))->toBeTrue($frase)
        ->and(str_contains($frase, '€ 142,94 a debito di Elsa'))->toBeTrue($frase);
});

it('annullare l\'inizio che aveva azzerato la fermata della fine dice quanto sommano le righe legate alla fine', function () {
    // O-DL7-DUEPASSI: la fine di Luca del 18/4 ferma € 268,20 verso Elsa, l'inizio di Marco lo stesso giorno la azzera.
    $s = b51aDl7ConVendita($this);
    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    foreach ([['Ugo', -26820], ['Elsa', 26820]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $fine->id)))->toBe([]);
    }
    $marco = b51aRegistraInizio($this, $s, 'Marco', '2026-04-18');

    $avviso = (string) collect(fds50Storico($s, $marco)['annullabile']['avvisi'] ?? [])->first(fn ($t) => str_contains($t, '268,20'));

    expect($avviso)->toStartWith('Con questo inizio la parte verso Elsa della fermata della fine della locazione di Luca del 18 aprile 2026 è scesa da € 268,20 a € 0,00: annullandolo torna da € 0,00 a € 268,20.')
        ->and(str_contains($avviso, 'I saldi del Wallet legati alla fine della locazione di Luca del 18 aprile 2026 sommano oggi € 268,20 a credito di Ugo e € 268,20 a debito di Elsa'))->toBeTrue($avviso)
        ->and(str_contains($avviso, 'o per questo hai registrato un saldo manuale dal Wallet'))->toBeFalse($avviso);
});

it('la preposizione davanti alle parole del passaggio: alla vendita, al legato, all\'inizio', function () {
    expect(FermataDopoScrivi::al('la vendita del 1 novembre 2026'))->toBe('alla vendita del 1 novembre 2026')
        ->and(FermataDopoScrivi::al('il legato del 1 maggio 2026'))->toBe('al legato del 1 maggio 2026')
        ->and(FermataDopoScrivi::al('l\'inizio della locazione di Luca del 1 dicembre 2026'))->toBe('all\'inizio della locazione di Luca del 1 dicembre 2026')
        ->and(FermataDopoScrivi::al('l\'estinzione dell\'usufrutto del 1 marzo 2026'))->toBe('all\'estinzione dell\'usufrutto del 1 marzo 2026');
});
