<?php

/**
 * 1.11.0-beta.53 (Coda 244) — le frasi della parte che risale, della fermata ridotta e degli annullamenti di una fine o di un inizio dicono i
 * fatti dei saldi del Wallet legati al passaggio della fermata (decisione 100, punto 2).
 *
 * Il caso INT (`b51aInt`): la fine di Ines il 1/7 con «Scrivi», la vendita di Ugo a Elsa il 1/10 si ferma sulla coppia (€ 90,74 verso Elsa);
 * l'amministratore registra dal Wallet i saldi legati alla vendita; Luca entra il 1/11 e la parte scende a € 30,58; Luca esce il 1/12 e la parte
 * risale a € 61,15. La frase non sceglie quale riga toccare né di quanto: dice, per ogni persona, quanto sommano oggi le sue righe legate e quanto
 * vale la sua parte; quali righe non si modificano più e perché (in un piano che non si riscrive più, in un esercizio che non è quello aperto);
 * e dove si fa il passo dopo: il Wallet corregge le righe che si modificano, e la porta accetta un saldo legato con la differenza da chi non ne
 * ha una che si modifica. Se le righe sommano già la parte, lo dice. Senza righe legate la frase resta quella di prima.
 *
 * Ogni frase è seguita dalle rotte (`sl53SeguiIFatti`), come la legge l'amministratore: chi ha una riga che si modifica la corregge, chi non ne ha
 * registra la differenza; la frase deve indicare la strada percorsa, nessuna rotta rifiuta, e le righe sommano la parte di ognuno.
 *
 * Fonte dell'atteso: la regola dei saldi legati (Coda 244), le cifre del caso INT (`Beta51PassaggiParteCheRisaleTest`) e il conto a mano scritto
 * in ogni test. Con la successione ad Anna e Bruno la parte di ognuno è quella del registro (Anna € 30,58, Bruno € 30,57 dopo la fine di Luca).
 *
 * Che cosa NON copre: lo storico e il motivo del cancello della parte che risale, che non nominano le righe; più persone a carico della stessa
 * fermata (la parte di quelle persone è una sola, e la dice la frase della fermata); MySQL.
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

const SL53R_RISALE = 'Con la fine della locazione di Luca i giorni dal 1 dicembre 2026 tornano a chi paga al posto dell\'inquilino: la parte verso Elsa della fermata della vendita del 1 ottobre 2026 sale da € 30,58 a € 61,15 sulla gestione Ordinaria 2026 (Interno 1). La parte da regolare a mano fra Ugo e Elsa è questa, e il programma non la sposta.';
const SL53R_LEGATI = ' I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi ';
const SL53R_NUOVA = '; la parte nuova è € 61,15 a credito di Ugo e € 61,15 a debito di Elsa.';
const SL53R_CORREGGI = ' Il programma non li cambia: correggi dal Wallet i saldi che si modificano.';
const SL53R_REGISTRA = ' Il programma non li cambia: registra dal Wallet la differenza con un saldo legato alla vendita del 1 ottobre 2026.';
const SL53R_TUTTE_E_DUE = ' Il programma non li cambia: correggi dal Wallet i saldi che si modificano, e per chi non ne ha uno registra la differenza con un saldo legato alla vendita del 1 ottobre 2026.';

/** «Non si modificano più quelli nel piano «Rate di fine anno», …» con le righe date. */
function sl53rFerme(string $righe, bool $una = false): string
{
    return sprintf(' Non si %s più %s nel piano «Rate di fine anno», che non si riscrive più (%s).', $una ? 'modifica' : 'modificano', $una ? 'quello' : 'quelli', $righe);
}

/**
 * Il caso INT fino all'inizio di Luca, con le due righe legate alla vendita scritte dopo la vendita e corrette al residuo dopo l'inizio, o
 * portate alla cifra data (`$aQuanto`); senza righe se `$legate` è falso.
 */
function sl53rCaso($test, bool $legate = true, ?int $aQuanto = null): array
{
    $s = fds50Scenario([['Ugo', 'proprietario', 100]]);
    fclPersona($s, 'Marco');
    fclPersona($s, 'Carlo');
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $s['fine_ines'] = fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($test, $s, '2026-09-30');
    $test->travel(1)->minutes();
    $s['vendita'] = fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    $s['cifra'] = (int) (fds50Fermate($s['vendita'])[0]['cifra'] ?? 0);
    expect($s['cifra'])->toBe(9074);
    $test->travel(1)->minutes();
    if ($legate) {
        sl53DueLegate($test, $s);
    }
    $test->travel(1)->minutes();
    $s['inizio_luca'] = fds50Registra($test, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    expect((int) (Subentro::find($s['inizio_luca']->id)->registro['fermata_ridotta'][0]['residuo'] ?? 0))->toBe(3058);
    $test->travel(1)->minutes();
    if ($legate) {
        foreach (Saldo::where('passaggio_id', $s['vendita']->id)->get() as $x) {
            $cifra = $aQuanto ?? 3058;
            $r = $test->actingAs($test->user)->from('/saldi')->put(route('admin.gestionale.saldi.update', [$s['c'], $x]), [
                'saldo_iniziale' => (int) $x->saldo_iniziale > 0 ? $cifra : -$cifra, 'gestione_id' => $s['g']->id]);
            expect(sl53Errori($r))->toBe([]);
        }
    }
    $test->travel(1)->minutes();

    return $s;
}

/** La frase del pannello della fine di Luca il 1/12 che dice la parte che risale. */
function sl53rFrase($test, array $s): string
{
    $a = fds50Anteprima($test, $s, b51aIntFine($s, '2026-12-01'));

    return (string) collect($a['rate']['frasi'])->first(fn (string $t) => str_contains($t, 'sale da'));
}

/** La fine di Luca il 1/12, registrata: la parte risale a € 61,15. */
function sl53rRegistraLaFine($test, array $s): Subentro
{
    $fine = fds50Registra($test, $s, fds50ModuloFine($s, Subentro::NON_SCRIVERE_IL_CONGUAGLIO, '2026-12-01', 'Luca'))['subentro'];
    expect((int) (Subentro::find($fine->id)->registro['fermata_risale'][0]['dopo'] ?? 0))->toBe(6115);
    $test->travel(1)->minutes();

    return $fine;
}

it('con le righe legate a € 30,58, la parte che risale dice quanto sommano e la parte nuova di ognuno, e di correggerle dal Wallet; seguita, le righe sommano € 61,15', function () {
    $s = sl53rCaso($this);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_RISALE . SL53R_LEGATI . '€ 30,58 a credito di Ugo e € 30,58 a debito di Elsa' . SL53R_NUOVA . SL53R_CORREGGI);

    // Conto a mano: Ugo −3058 − 3057 = −6115, Elsa 3058 + 3057 = 6115, sulle righe che si modificano.
    sl53rRegistraLaFine($this, $s);
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6115, 'Elsa' => 6115], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6115, 'Ugo' => -6115]);
});

it('con le righe legate già a € 61,15, la parte che risale dice che sono già la parte nuova', function () {
    $s = sl53rCaso($this, aQuanto: 6115);

    expect(sl53rFrase($this, $s))->toBe(SL53R_RISALE . SL53R_LEGATI . '€ 61,15 a credito di Ugo e € 61,15 a debito di Elsa: sono già la parte nuova.');
});

it('controprova — senza righe legate la frase della parte che risale resta quella di prima, e non nomina il Wallet', function () {
    $s = sl53rCaso($this, legate: false);

    expect(sl53rFrase($this, $s))->toBe(SL53R_RISALE);
});

/** Un piano che applica i saldi assorbe le righe legate libere della gestione ed emette: le righe restano ferme nel suo piano. */
function sl53rPianoEmesso($test, array $s): \App\Models\Gestionale\PianoRate
{
    $p = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Rate di fine anno', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-11-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
    fds50Genera($s, $p);
    fds50Emetti($test, $s, '2026-12-31', $p);
    $righe = Saldo::with('pianoRate')->where('passaggio_id', $s['vendita']->id)->get();
    expect($righe)->toHaveCount(2)
        ->and($righe->every(fn (Saldo $x) => (int) $x->piano_rate_id === (int) $p->id && $x->eBloccato()))->toBeTrue();

    return $p;
}

it('con le righe legate a € 30,58 già in un piano emesso, la parte che risale dice che non si modificano più e di registrare la differenza; seguita, la porta la accetta e le righe sommano € 61,15', function () {
    $s = sl53rCaso($this);
    sl53rPianoEmesso($this, $s);
    $this->travel(1)->minutes();

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_RISALE . SL53R_LEGATI . '€ 30,58 a credito di Ugo e € 30,58 a debito di Elsa' . SL53R_NUOVA
        . sl53rFerme('€ 30,58 a credito di Ugo, € 30,58 a debito di Elsa') . SL53R_REGISTRA);

    // Che non si modificano più, dalla rotta: la modifica di una riga ferma si rifiuta.
    $riga = Saldo::where('passaggio_id', $s['vendita']->id)->where('saldo_iniziale', '>', 0)->firstOrFail();
    expect(sl53Errori($this->actingAs($this->user)->from('/saldi')->put(route('admin.gestionale.saldi.update', [$s['c'], $riga]), ['saldo_iniziale' => 6115, 'gestione_id' => $s['g']->id]))['saldo'][0] ?? '')
        ->toStartWith('Non puoi modificare questo saldo');
    // Seguita dalla porta. Conto a mano: Ugo −3058 − 3057 = −6115, Elsa 3058 + 3057 = 6115.
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6115, 'Elsa' => 6115], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6115, 'Ugo' => -6115]);
});

/** La frase della fermata ridotta all'inizio di Luca il 1/11, dall'anteprima. */
function sl53rFraseInizio($test, array $s): string
{
    $a = fds50Anteprima($test, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));

    return (string) collect(fds50Testi($a))->first(fn (string $t) => str_contains($t, 'Con questo inizio') && str_contains($t, 'Wallet'));
}

const SL53R_INIZIO = 'La vendita del 1 ottobre 2026 ha lasciato a carico di Ugo € 90,74 dei giorni dopo l\'uscita di Ines, da regolare a mano con Elsa. Con questo inizio i giorni dal 1 novembre 2026 li rimborsa Luca con il prospetto degli oneri accessori: resta da regolare € 30,58 con Elsa. Se Ugo e Elsa hanno già regolato € 90,74, € 60,16 vanno restituiti a Elsa.';
const SL53R_RESTA = '; la parte che resta è € 30,58 a credito di Ugo e € 30,58 a debito di Elsa.';

it('con le righe legate a € 90,74 già in un piano emesso, l\'inizio di Luca che riduce la fermata dice la parte che resta e che le righe non si modificano più; seguita, la porta accetta la differenza e le righe sommano € 30,58', function () {
    $s = sl53rCaso($this, legate: false);
    // Il caso INT con le righe registrate prima dell'inizio di Luca: si annulla l'inizio (l'ultimo passaggio), si registrano le righe della fermata
    // (€ 90,74), un piano le assorbe ed emette.
    expect(sl53Errori(sl53Annulla($this, $s, $s['inizio_luca'], 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    $this->travel(1)->minutes();
    sl53DueLegate($this, $s);
    sl53rPianoEmesso($this, $s);
    $this->travel(1)->minutes();

    $frase = sl53rFraseInizio($this, $s);
    expect($frase)->toBe(SL53R_INIZIO . SL53R_LEGATI . '€ 90,74 a credito di Ugo e € 90,74 a debito di Elsa' . SL53R_RESTA
        . sl53rFerme('€ 90,74 a credito di Ugo, € 90,74 a debito di Elsa') . SL53R_REGISTRA);

    // Seguita dalla porta, dopo l'inizio. Conto a mano: Ugo −9074 + 6016 = −3058, Elsa 9074 − 6016 = 3058.
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -3058, 'Elsa' => 3058], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
});

it('con le righe della fermata (€ 60,16) già in un piano emesso, l\'inizio di Luca che azzera la fermata dice che la parte che resta è € 0,00; seguita, la porta accetta le differenze e le righe sommano zero', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);
    sl53rPianoEmesso($this, $s);
    $this->travel(1)->minutes();

    $a = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $frase = (string) collect(fds50Testi($a))->first(fn (string $t) => str_contains($t, 'Con questo inizio') && str_contains($t, 'Wallet'));
    expect($frase)->toContain('non resta niente da regolare fra le parti')
        ->and($frase)->toEndWith(' I saldi del Wallet legati alla vendita del 1 novembre 2026 sommano oggi € 60,16 a credito di Ugo e € 60,16 a debito di Elsa; la parte che resta è € 0,00. Non si modificano più quelli nel piano «Rate di fine anno», che non si riscrive più (€ 60,16 a credito di Ugo, € 60,16 a debito di Elsa). Il programma non li cambia: registra dal Wallet la differenza con un saldo legato alla vendita del 1 novembre 2026.');

    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => 0, 'Elsa' => 0], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 0, 'Ugo' => 0]);
});

it('con la riga legata di Ugo già in un piano emesso e quella di Elsa libera, la parte che risale nomina quella ferma e dice le due strade; seguita, le righe sommano € 61,15', function () {
    $s = sl53rCaso($this, legate: false);
    // La riga di Ugo, poi un piano che la assorbe ed emette; poi quella di Elsa, libera.
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', -3058, (int) $s['vendita']->id)))->toBe([]);
    sl53rFineAnno($this, $s);
    $this->travel(1)->minutes();
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 3058, (int) $s['vendita']->id)))->toBe([]);
    $this->travel(1)->minutes();

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_RISALE . SL53R_LEGATI . '€ 30,58 a credito di Ugo e € 30,58 a debito di Elsa' . SL53R_NUOVA
        . sl53rFerme('€ 30,58 a credito di Ugo', una: true) . SL53R_TUTTE_E_DUE);

    // Seguita: la riga libera di Elsa si corregge a 6115, per Ugo la differenza (−3057) dalla porta.
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6115, 'Elsa' => 6115], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6115, 'Ugo' => -6115]);
});

// --- Più persone che ricevono, e gli avvisi dell'annullamento con le righe ferme -------------------------------------------------------------

const SL53R_SUCCESSIONE = 'Con la fine della locazione di Luca i giorni dal 1 dicembre 2026 tornano a chi paga al posto dell\'inquilino: la parte verso Anna e Bruno della fermata della successione del 1 ottobre 2026 sale da € 30,58 a € 61,15 sulla gestione Ordinaria 2026 (Interno 1). La parte da regolare a mano fra Ugo e Anna e Bruno è questa, e il programma non la sposta.';
const SL53R_SUCC_LEGATI = ' I saldi del Wallet legati alla successione del 1 ottobre 2026 sommano oggi ';
const SL53R_SUCC_NUOVA = '; la parte nuova è € 61,15 a credito di Ugo, € 30,58 a debito di Anna e € 30,57 a debito di Bruno.';
const SL53R_SUCC_FERME = ' Non si modificano più quelli nel piano «Rate di fine anno», che non si riscrive più (€ 90,74 a credito di Ugo, € 45,37 a debito di Anna, € 45,37 a debito di Bruno).';

/**
 * La successione di Ugo il 1/10 ad Anna e Bruno al 50 %, con l'arretrato al defunto e «Scrivi», dopo la fine di Ines con «Scrivi»: la fermata è
 * € 90,74 verso Anna e Bruno (€ 45,37 ciascuno); Luca entra il 1/11 e la parte scende a € 30,58 (€ 15,29 ciascuno). Senza righe legate.
 */
function sl53rSuccessione($test): array
{
    $s = fds50Scenario([['Ugo', 'proprietario', 100]]);
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $s['fine_ines'] = fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($test, $s, '2026-09-30');
    $test->travel(1)->minutes();
    $s['successione'] = fds50Registra($test, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, '2026-10-01'))['subentro'];
    expect(collect(fds50Fermate($s['successione']))->map(fn ($f) => [$f['persona'], (int) $f['cifra'], $f['verso']])->all())->toBe([['Ugo', 9074, ['Anna', 'Bruno']]]);
    $test->travel(1)->minutes();
    $s['inizio_luca'] = fds50Registra($test, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    expect((int) (Subentro::find($s['inizio_luca']->id)->registro['fermata_ridotta'][0]['residuo'] ?? 0))->toBe(3058);
    $test->travel(1)->minutes();

    return $s;
}

/** Un piano «Rate di fine anno» che applica i saldi: assorbe le righe libere della gestione ed emette. */
function sl53rFineAnno($test, array $s): \App\Models\Gestionale\PianoRate
{
    $p = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Rate di fine anno', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-11-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
    fds50Genera($s, $p);
    fds50Emetti($test, $s, '2026-12-31', $p);

    return $p;
}

it('con più persone che ricevono e le righe legate tutte in un piano emesso, la parte che risale dice la parte nuova di ognuno; seguita, la porta accetta le differenze e le righe sommano € 61,15', function () {
    $s = sl53rSuccessione($this);
    foreach ([['Ugo', -3058], ['Anna', 1529], ['Bruno', 1529]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['successione']->id)))->toBe([]);
    }
    $p = sl53rFineAnno($this, $s);
    expect(Saldo::where('passaggio_id', $s['successione']->id)->get()->every(fn (Saldo $x) => (int) $x->piano_rate_id === (int) $p->id && $x->eBloccato()))->toBeTrue();
    $this->travel(1)->minutes();

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_SUCCESSIONE . SL53R_SUCC_LEGATI . '€ 30,58 a credito di Ugo, € 15,29 a debito di Anna e € 15,29 a debito di Bruno' . SL53R_SUCC_NUOVA
        . ' Non si modificano più quelli nel piano «Rate di fine anno», che non si riscrive più (€ 30,58 a credito di Ugo, € 15,29 a debito di Anna, € 15,29 a debito di Bruno). Il programma non li cambia: registra dal Wallet la differenza con un saldo legato alla successione del 1 ottobre 2026.');

    // Seguita dalla porta. Conto a mano: Ugo −3058 − 3057 = −6115; Anna 1529 + 1529 = 3058, Bruno 1529 + 1528 = 3057.
    expect(sl53SeguiIFatti($this, $s, $s['successione'], ['Ugo' => -6115, 'Anna' => 3058, 'Bruno' => 3057], $frase))->toBe([]);
    expect(sl53Legate($s, $s['successione']))->toBe(['Anna' => 3058, 'Bruno' => 3057, 'Ugo' => -6115]);
});

it('con più persone che ricevono, la riga di Ugo in un piano emesso e quelle di Anna e Bruno libere, la parte che risale nomina quella ferma e dice le due strade; seguita, le righe sommano € 61,15', function () {
    $s = sl53rSuccessione($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', -3058, (int) $s['successione']->id)))->toBe([]);
    sl53rFineAnno($this, $s);
    $this->travel(1)->minutes();
    foreach ([['Anna', 1529], ['Bruno', 1529]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['successione']->id)))->toBe([]);
    }
    $this->travel(1)->minutes();

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_SUCCESSIONE . SL53R_SUCC_LEGATI . '€ 30,58 a credito di Ugo, € 15,29 a debito di Anna e € 15,29 a debito di Bruno' . SL53R_SUCC_NUOVA
        . ' Non si modifica più quello nel piano «Rate di fine anno», che non si riscrive più (€ 30,58 a credito di Ugo). Il programma non li cambia: correggi dal Wallet i saldi che si modificano, e per chi non ne ha uno registra la differenza con un saldo legato alla successione del 1 ottobre 2026.');

    // Seguita: le righe libere di Anna e Bruno si correggono, per Ugo la differenza dalla porta.
    expect(sl53SeguiIFatti($this, $s, $s['successione'], ['Ugo' => -6115, 'Anna' => 3058, 'Bruno' => 3057], $frase))->toBe([]);
    expect(sl53Legate($s, $s['successione']))->toBe(['Anna' => 3058, 'Bruno' => 3057, 'Ugo' => -6115]);
});

/**
 * Una voce tutta del proprietario («Spese di proprietà», € 120,00) e un piano che copre solo lei, applica i saldi, si genera ed emette. Le sue
 * quote non hanno righe del riparto con il ruolo dell'inquilino: un passaggio di una locazione registrato prima non le cambia, e la loro
 * emissione non ne ferma l'annullamento.
 */
function sl53rPianoDelProprietario($test, array $s, string $nome, string $prima): \App\Models\Gestionale\PianoRate
{
    $conto = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $s['conto']->piano_conto_id, 'nome' => 'Spese di proprietà', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 12000]);
    $ctm = \Illuminate\Support\Facades\DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => \Illuminate\Support\Facades\DB::table('tabelle')->where('condominio_id', $s['c']->id)->value('id'),
        'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    \Illuminate\Support\Facades\DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $p = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => $nome, 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => (int) substr($prima, 8, 2), 'data_prima_scadenza' => $prima, 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
    $p->capitoli()->attach($conto->id, ['importo' => 12000]);
    fds50Genera($s, $p);
    fds50Emetti($test, $s, '2026-12-31', $p);

    return $p;
}

it('l\'annullamento della fine di Luca, con le righe tutte in piani emessi: l\'avviso dice la parte che torna e che le righe non si modificano più; seguito, la porta accetta la differenza e le righe sommano € 30,58', function () {
    $s = sl53rCaso($this);
    // Le righe a € 30,58 in un piano emesso prima della fine di Luca; la fine; la differenza (€ 30,57) dalla porta, poi in un piano emesso dopo.
    $p1 = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Rate di novembre', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-11-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
    fds50Genera($s, $p1);
    fds50Emetti($this, $s, '2026-12-31', $p1);
    $this->travel(1)->minutes();
    $fine = sl53rRegistraLaFine($this, $s);
    foreach ([['Ugo', -3057], ['Elsa', 3057]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id)))->toBe([]);
    }
    $this->travel(1)->minutes();
    sl53rPianoDelProprietario($this, $s, 'Rate di dicembre', '2026-12-20');
    $this->travel(1)->minutes();
    expect(Saldo::with('pianoRate')->where('passaggio_id', $s['vendita']->id)->get()->every(fn (Saldo $x) => $x->eBloccato()))->toBeTrue();

    $storico = fds50Storico($s, $fine->fresh());
    expect($storico['annullabile']['motivo'] ?? null)->toBeNull();
    $avviso = (string) collect($storico['annullabile']['avvisi'] ?? [])->first(fn (string $t) => str_contains($t, 'è risalita'));
    expect($avviso)->toBe('Con questo passaggio la parte verso Elsa della fermata della vendita del 1 ottobre 2026 è risalita da € 30,58 a € 61,15: annullandolo torna da € 61,15 a € 30,58. Se le parti hanno regolato la differenza fra loro, quell\'accordo va rivisto. I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 61,15 a credito di Ugo e € 61,15 a debito di Elsa; la parte che torna è € 30,58 a credito di Ugo e € 30,58 a debito di Elsa. Non si modificano più quelli nei piani «Rate di novembre», «Rate di dicembre», che non si riscrivono più (€ 30,58 a credito di Ugo, € 30,58 a debito di Elsa, € 30,57 a credito di Ugo, € 30,57 a debito di Elsa). Annullare il passaggio non li cambia: registra dal Wallet la differenza con un saldo legato alla vendita del 1 ottobre 2026.');

    // Seguito dalle rotte: la fine si annulla, la porta accetta le differenze. Conto a mano: Ugo −6115 + 3057 = −3058, Elsa 6115 − 3057 = 3058.
    expect(sl53Errori(sl53Annulla($this, $s, $fine, 'Registrata per sbaglio, la locazione continua')))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -3058, 'Elsa' => 3058], $avviso))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
});

it('l\'annullamento dell\'inizio di Luca, con le righe della fermata ridotta tutte in piani emessi: l\'avviso dice la parte che torna e che le righe non si modificano più; seguito, la porta accetta la differenza e le righe sommano € 90,74', function () {
    $s = sl53rCaso($this, legate: false);
    // Le righe della fermata (€ 90,74) prima dell'inizio di Luca, in un piano emesso; l'inizio; la differenza (€ 60,16) dalla porta, poi in un
    // piano emesso dopo.
    expect(sl53Errori(sl53Annulla($this, $s, $s['inizio_luca'], 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    $this->travel(1)->minutes();
    sl53DueLegate($this, $s);
    sl53rPianoEmesso($this, $s);
    $this->travel(1)->minutes();
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    $this->travel(1)->minutes();
    foreach ([['Ugo', 6016], ['Elsa', -6016]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id)))->toBe([]);
    }
    $this->travel(1)->minutes();
    sl53rPianoDelProprietario($this, $s, 'Rate di dicembre', '2026-12-20');
    $this->travel(1)->minutes();
    expect(Saldo::with('pianoRate')->where('passaggio_id', $s['vendita']->id)->get()->every(fn (Saldo $x) => $x->eBloccato()))->toBeTrue();

    $avviso = sl53rAvvisoInizio($s, $inizio);
    expect($avviso)->toBe('Con questo inizio la parte verso Elsa della fermata della vendita del 1 ottobre 2026 è scesa da € 90,74 a € 30,58: annullandolo torna da € 30,58 a € 90,74. Se le parti hanno già regolato la differenza fra loro, quell\'accordo va rivisto. I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 30,58 a credito di Ugo e € 30,58 a debito di Elsa; la parte che torna è € 90,74 a credito di Ugo e € 90,74 a debito di Elsa. Non si modificano più quelli nei piani «Rate di fine anno», «Rate di dicembre», che non si riscrivono più (€ 90,74 a credito di Ugo, € 90,74 a debito di Elsa, € 60,16 a debito di Ugo, € 60,16 a credito di Elsa). Annullare il passaggio non li cambia: registra dal Wallet la differenza con un saldo legato alla vendita del 1 ottobre 2026.');

    // Seguito dalle rotte: l'inizio si annulla, la porta accetta le differenze. Conto a mano: Ugo −3058 − 6016 = −9074, Elsa 3058 + 6016 = 9074.
    expect(sl53Errori(sl53Annulla($this, $s, $inizio, 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -9074, 'Elsa' => 9074], $avviso))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 9074, 'Ugo' => -9074]);
});

// --- Righe ferme e righe libere della stessa persona ------------------------------------------------------------------------------------------

/**
 * Il caso INT con le righe della fermata (€ 90,74) registrate prima dell'inizio di Luca e ferme in un piano emesso; poi l'inizio di Luca e, dalla
 * porta, la differenza per le persone date (Ugo € 60,16 a debito, Elsa € 60,16 a credito), libera: chi la registra ha una riga ferma e una
 * libera, che sommano € 30,58.
 */
function sl53rFermeELibere($test, array $conLaDifferenza = ['Ugo', 'Elsa']): array
{
    $s = sl53rCaso($test, legate: false);
    expect(sl53Errori(sl53Annulla($test, $s, $s['inizio_luca'], 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    $test->travel(1)->minutes();
    sl53DueLegate($test, $s);
    sl53rPianoEmesso($test, $s);
    $test->travel(1)->minutes();
    $s['inizio_luca'] = fds50Registra($test, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    $test->travel(1)->minutes();
    foreach (array_intersect_key(['Ugo' => 6016, 'Elsa' => -6016], array_flip($conLaDifferenza)) as $chi => $cents) {
        expect(sl53Errori(sl53Posta($test, $s, $chi, $cents, (int) $s['vendita']->id)))->toBe([]);
    }
    $test->travel(1)->minutes();

    return $s;
}

/** L'avviso dell'annullamento dell'inizio di Luca che dice la parte che torna. */
function sl53rAvvisoInizio(array $s, Subentro $inizio): string
{
    $storico = fds50Storico($s, $inizio->fresh());
    expect($storico['annullabile']['motivo'] ?? null)->toBeNull();

    return (string) collect($storico['annullabile']['avvisi'] ?? [])->first(fn (string $t) => str_contains($t, 'è scesa'));
}

it('con una riga ferma e una libera per Ugo e per Elsa, la parte che risale nomina le ferme e dice di correggere dal Wallet; seguita, le righe sommano € 61,15', function () {
    $s = sl53rFermeELibere($this);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_RISALE . SL53R_LEGATI . '€ 30,58 a credito di Ugo e € 30,58 a debito di Elsa' . SL53R_NUOVA
        . sl53rFerme('€ 90,74 a credito di Ugo, € 90,74 a debito di Elsa') . SL53R_CORREGGI);

    // Seguita dalle rotte, senza rifiuti. Conto a mano: le righe libere di Ugo 6016 − 3057 = 2959 e di Elsa −6016 + 3057 = −2959.
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6115, 'Elsa' => 6115], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6115, 'Ugo' => -6115]);
});

it('con la riga di Ugo solo ferma e quelle di Elsa divise fra una ferma e una libera, la parte che risale dice le due strade; seguita, le righe sommano € 61,15', function () {
    $s = sl53rFermeELibere($this, ['Elsa']);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -9074]);

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_RISALE . SL53R_LEGATI . '€ 90,74 a credito di Ugo e € 30,58 a debito di Elsa' . SL53R_NUOVA
        . sl53rFerme('€ 90,74 a credito di Ugo, € 90,74 a debito di Elsa') . SL53R_TUTTE_E_DUE);

    // Conto a mano: la riga libera di Elsa −6016 − 3057 + 6115 … = −2959; per Ugo la differenza −6115 + 9074 = 2959 dalla porta.
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6115, 'Elsa' => 6115], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6115, 'Ugo' => -6115]);
});

it('l\'annullamento della fine di Luca, con le righe di ognuno divise fra una ferma e una libera che sommano già € 30,58: l\'avviso dice che sono già la parte che torna; seguito, la fine si annulla e le righe restano € 30,58', function () {
    $s = sl53rFermeELibere($this);
    // Le parti regolano fra loro la parte che risale: le righe restano come sono.
    $fine = sl53rRegistraLaFine($this, $s);

    $storico = fds50Storico($s, $fine->fresh());
    expect($storico['annullabile']['motivo'] ?? null)->toBeNull();
    expect((string) collect($storico['annullabile']['avvisi'] ?? [])->first(fn (string $t) => str_contains($t, 'è risalita')))
        ->toBe('Con questo passaggio la parte verso Elsa della fermata della vendita del 1 ottobre 2026 è risalita da € 30,58 a € 61,15: annullandolo torna da € 61,15 a € 30,58. Se le parti hanno regolato la differenza fra loro, quell\'accordo va rivisto. I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 30,58 a credito di Ugo e € 30,58 a debito di Elsa: sono già la parte che torna.');

    expect(sl53Errori(sl53Annulla($this, $s, $fine, 'Registrata per sbaglio, la locazione continua')))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
});

/**
 * La successione ad Anna e Bruno con le righe della fermata (€ 90,74) ferme in un piano emesso, e dalla porta le differenze dopo l'inizio di Luca
 * per le persone date, libere (Ugo € 60,16 a debito, Anna e Bruno € 30,08 a credito ciascuno).
 */
function sl53rSuccessioneFermeELibere($test, array $conLaDifferenza): array
{
    $s = sl53rSuccessione($test);
    foreach ([['Ugo', -9074], ['Anna', 4537], ['Bruno', 4537]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($test, $s, $chi, $cents, (int) $s['successione']->id)))->toBe([]);
    }
    sl53rFineAnno($test, $s);
    $test->travel(1)->minutes();
    foreach (array_intersect_key(['Ugo' => 6016, 'Anna' => -3008, 'Bruno' => -3008], array_flip($conLaDifferenza)) as $chi => $cents) {
        expect(sl53Errori(sl53Posta($test, $s, $chi, $cents, (int) $s['successione']->id)))->toBe([]);
    }
    $test->travel(1)->minutes();

    return $s;
}

it('con più persone che ricevono e una riga ferma e una libera per ognuno, la parte che risale e l\'avviso dell\'annullamento della fine dicono di correggere dal Wallet; seguiti dalle rotte, nessun rifiuto, e le righe sommano € 61,15 e poi € 30,58', function () {
    $s = sl53rSuccessioneFermeELibere($this, ['Ugo', 'Anna', 'Bruno']);

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_SUCCESSIONE . SL53R_SUCC_LEGATI . '€ 30,58 a credito di Ugo, € 15,29 a debito di Anna e € 15,29 a debito di Bruno' . SL53R_SUCC_NUOVA
        . SL53R_SUCC_FERME . SL53R_CORREGGI);

    // La frase seguita, dalle rotte: le righe libere. Conto a mano: Ugo 6016 − 3057 = 2959, Anna −3008 + 1529 = −1479, Bruno −3008 + 1528 = −1480.
    $fine = sl53rRegistraLaFine($this, $s);
    expect(sl53SeguiIFatti($this, $s, $s['successione'], ['Ugo' => -6115, 'Anna' => 3058, 'Bruno' => 3057], $frase))->toBe([]);
    expect(sl53Legate($s, $s['successione']))->toBe(['Anna' => 3058, 'Bruno' => 3057, 'Ugo' => -6115]);
    $this->travel(1)->minutes();

    $storico = fds50Storico($s, $fine->fresh());
    expect($storico['annullabile']['motivo'] ?? null)->toBeNull();
    $avviso = (string) collect($storico['annullabile']['avvisi'] ?? [])->first(fn (string $t) => str_contains($t, 'è risalita'));
    expect($avviso)->toBe('Con questo passaggio la parte verso Anna e Bruno della fermata della successione del 1 ottobre 2026 è risalita da € 30,58 a € 61,15: annullandolo torna da € 61,15 a € 30,58. Se le parti hanno regolato la differenza fra loro, quell\'accordo va rivisto. I saldi del Wallet legati alla successione del 1 ottobre 2026 sommano oggi € 61,15 a credito di Ugo, € 30,58 a debito di Anna e € 30,57 a debito di Bruno; la parte che torna è € 30,58 a credito di Ugo, € 15,29 a debito di Anna e € 15,29 a debito di Bruno.'
        . SL53R_SUCC_FERME . ' Annullare il passaggio non li cambia: correggi dal Wallet i saldi che si modificano.');

    // L'avviso seguito, dalle rotte: la fine si annulla e le righe libere tornano alla parte di prima.
    expect(sl53Errori(sl53Annulla($this, $s, $fine, 'Registrata per sbaglio, la locazione continua')))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $s['successione'], ['Ugo' => -3058, 'Anna' => 1529, 'Bruno' => 1529], $avviso))->toBe([]);
    expect(sl53Legate($s, $s['successione']))->toBe(['Anna' => 1529, 'Bruno' => 1529, 'Ugo' => -3058]);
});

it('con più persone che ricevono, la riga di Ugo solo ferma e quelle di Anna e Bruno divise fra una ferma e una libera, la parte che risale dice le due strade; seguita, nessun rifiuto, e le righe sommano € 61,15', function () {
    $s = sl53rSuccessioneFermeELibere($this, ['Anna', 'Bruno']);

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_SUCCESSIONE . SL53R_SUCC_LEGATI . '€ 90,74 a credito di Ugo, € 15,29 a debito di Anna e € 15,29 a debito di Bruno' . SL53R_SUCC_NUOVA
        . SL53R_SUCC_FERME . ' Il programma non li cambia: correggi dal Wallet i saldi che si modificano, e per chi non ne ha uno registra la differenza con un saldo legato alla successione del 1 ottobre 2026.');

    // Conto a mano: Anna −3008 + 1529 = −1479, Bruno −3008 + 1528 = −1480 sulle righe libere; Ugo −6115 + 9074 = 2959 dalla porta.
    expect(sl53SeguiIFatti($this, $s, $s['successione'], ['Ugo' => -6115, 'Anna' => 3058, 'Bruno' => 3057], $frase))->toBe([]);
    expect(sl53Legate($s, $s['successione']))->toBe(['Anna' => 3058, 'Bruno' => 3057, 'Ugo' => -6115]);
});

it('con la riga di Ugo divisa fra una ferma e una libera e quella di Elsa solo libera, la parte che risale nomina quella ferma e dice di correggere dal Wallet; seguita, le righe sommano € 61,15', function () {
    $s = sl53rCaso($this, legate: false);
    expect(sl53Errori(sl53Annulla($this, $s, $s['inizio_luca'], 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    $this->travel(1)->minutes();
    // La riga di Ugo, poi un piano che la assorbe ed emette; poi quella di Elsa, libera; l'inizio di Luca, e la parte che resta: Ugo con un saldo
    // legato in più, Elsa correggendo la sua.
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', -9074, (int) $s['vendita']->id)))->toBe([]);
    sl53rFineAnno($this, $s);
    $this->travel(1)->minutes();
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 9074, (int) $s['vendita']->id)))->toBe([]);
    $this->travel(1)->minutes();
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', 6016, (int) $s['vendita']->id)))->toBe([]);
    $elsa = Saldo::where('passaggio_id', $s['vendita']->id)->where('anagrafica_id', $s['persone']['Elsa']->id)->sole();
    expect(sl53Errori($this->actingAs($this->user)->from('/saldi')->put(route('admin.gestionale.saldi.update', [$s['c'], $elsa]), ['saldo_iniziale' => 3058, 'gestione_id' => $s['g']->id])))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
    $this->travel(1)->minutes();

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_RISALE . SL53R_LEGATI . '€ 30,58 a credito di Ugo e € 30,58 a debito di Elsa' . SL53R_NUOVA . sl53rFerme('€ 90,74 a credito di Ugo', una: true) . SL53R_CORREGGI);

    // Conto a mano: Elsa 3058 + 3057 = 6115; la riga libera di Ugo 6016 − 3057 = 2959.
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6115, 'Elsa' => 6115], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6115, 'Ugo' => -6115]);
});

it('con più persone che ricevono, la riga di Ugo divisa fra una ferma e una libera e quelle di Anna e Bruno solo libere, la parte che risale dice di correggere dal Wallet; seguita, le righe sommano € 61,15', function () {
    $s = sl53rSuccessione($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', -9074, (int) $s['successione']->id)))->toBe([]);
    sl53rFineAnno($this, $s);
    $this->travel(1)->minutes();
    foreach ([['Anna', 1529], ['Bruno', 1529], ['Ugo', 6016]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['successione']->id)))->toBe([]);
    }
    $this->travel(1)->minutes();

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_SUCCESSIONE . SL53R_SUCC_LEGATI . '€ 30,58 a credito di Ugo, € 15,29 a debito di Anna e € 15,29 a debito di Bruno' . SL53R_SUCC_NUOVA
        . ' Non si modifica più quello nel piano «Rate di fine anno», che non si riscrive più (€ 90,74 a credito di Ugo).' . SL53R_CORREGGI);

    // Conto a mano: Ugo 6016 − 3057 = 2959 sulla riga libera, Anna 3058, Bruno 3057.
    expect(sl53SeguiIFatti($this, $s, $s['successione'], ['Ugo' => -6115, 'Anna' => 3058, 'Bruno' => 3057], $frase))->toBe([]);
    expect(sl53Legate($s, $s['successione']))->toBe(['Anna' => 3058, 'Bruno' => 3057, 'Ugo' => -6115]);
});

// --- Le righe libere a zero, e quale riga è libera ------------------------------------------------------------------------------------------

/**
 * La porta conta come doppione anche una riga libera a € 0,00 della stessa persona: la frase la conta fra quelle che si modificano, e chi la ha la
 * corregge. Una riga di un esercizio che non è quello aperto non si modifica: la pagina dei saldi non la mostra, e la porta accetta la differenza
 * nell'esercizio aperto.
 */

it('l\'annullamento dell\'inizio di Luca, con una riga ferma e una libera per Ugo e per Elsa: l\'avviso dice la parte che torna e di correggere dal Wallet; seguito dalle rotte, e poi l\'inizio rifatto con la sua frase, nessun rifiuto', function () {
    $s = sl53rFermeELibere($this);

    $avviso = sl53rAvvisoInizio($s, $s['inizio_luca']);
    expect($avviso)->toBe('Con questo inizio la parte verso Elsa della fermata della vendita del 1 ottobre 2026 è scesa da € 90,74 a € 30,58: annullandolo torna da € 30,58 a € 90,74. Se le parti hanno già regolato la differenza fra loro, quell\'accordo va rivisto. I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 30,58 a credito di Ugo e € 30,58 a debito di Elsa; la parte che torna è € 90,74 a credito di Ugo e € 90,74 a debito di Elsa.'
        . sl53rFerme('€ 90,74 a credito di Ugo, € 90,74 a debito di Elsa') . ' Annullare il passaggio non li cambia: correggi dal Wallet i saldi che si modificano.');

    // Seguito dalle rotte: l'inizio si annulla, le due righe libere tornano a zero. Conto a mano: Ugo −9074, Elsa 9074.
    expect(sl53Errori(sl53Annulla($this, $s, $s['inizio_luca'], 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -9074, 'Elsa' => 9074], $avviso))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 9074, 'Ugo' => -9074]);
    $this->travel(1)->minutes();

    // L'inizio rifatto: le righe a zero sono quelle che si modificano. Conto a mano: Ugo −9074 + 6016 = −3058, Elsa 9074 − 6016 = 3058.
    $frase = sl53rFraseInizio($this, $s);
    expect($frase)->toBe(SL53R_INIZIO . SL53R_LEGATI . '€ 90,74 a credito di Ugo e € 90,74 a debito di Elsa' . SL53R_RESTA . sl53rFerme('€ 90,74 a credito di Ugo, € 90,74 a debito di Elsa') . SL53R_CORREGGI);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -3058, 'Elsa' => 3058], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
});

it('con le righe libere eliminate dal Wallet, l\'inizio rifatto dice di registrare la differenza; seguita dalla porta, nessun rifiuto, e le righe sommano € 30,58', function () {
    $s = sl53rFermeELibere($this);
    expect(sl53Errori(sl53Annulla($this, $s, $s['inizio_luca'], 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    $this->travel(1)->minutes();
    foreach (Saldo::with('pianoRate')->where('passaggio_id', $s['vendita']->id)->get()->reject(fn (Saldo $x) => $x->eBloccato()) as $x) {
        expect(sl53Errori($this->actingAs($this->user)->from('/saldi')->delete(route('admin.gestionale.saldi.destroy', [$s['c'], $x]))))->toBe([]);
    }
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 9074, 'Ugo' => -9074]);
    $this->travel(1)->minutes();

    $frase = sl53rFraseInizio($this, $s);
    expect($frase)->toBe(SL53R_INIZIO . SL53R_LEGATI . '€ 90,74 a credito di Ugo e € 90,74 a debito di Elsa' . SL53R_RESTA . sl53rFerme('€ 90,74 a credito di Ugo, € 90,74 a debito di Elsa') . SL53R_REGISTRA);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -3058, 'Elsa' => 3058], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
});

it('con le righe libere portate a € 0,00, la porta rifiuta un saldo legato in più e la frase non lo propone: dice di correggere dal Wallet; seguita, nessun rifiuto, e le righe sommano € 30,58', function () {
    $s = sl53rFermeELibere($this);
    expect(sl53Errori(sl53Annulla($this, $s, $s['inizio_luca'], 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -9074, 'Elsa' => 9074], 'correggi dal Wallet'))->toBe([]);
    expect(Saldo::where('passaggio_id', $s['vendita']->id)->where('saldo_iniziale', 0)->count())->toBe(2);
    $this->travel(1)->minutes();

    $frase = sl53rFraseInizio($this, $s);
    expect($frase)->toEndWith(SL53R_CORREGGI)
        ->and($frase)->not->toContain('la differenza con un saldo legato');
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    // La porta rifiuta un saldo legato in più: la riga a zero è il doppione. La frase seguita, dalla modifica, passa.
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', 6016, (int) $s['vendita']->id)))->toHaveKey('anagrafica_id');
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -3058, 'Elsa' => 3058], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
});

it('l\'annullamento dell\'inizio di Luca, con righe ferme e libere di cifre diverse: l\'avviso dice quanto sommano e la parte che torna; seguito dalle rotte, le righe sommano € 90,74', function () {
    $s = sl53rCaso($this, legate: false);
    expect(sl53Errori(sl53Annulla($this, $s, $s['inizio_luca'], 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    $this->travel(1)->minutes();
    // Le righe che l'amministratore ha scritto (Ugo € 90,74 a credito, Elsa € 30,58 a debito), ferme in un piano emesso; l'inizio di Luca; due righe
    // libere (Ugo € 60,16 a debito, Elsa € 10,00 a debito).
    foreach ([['Ugo', -9074], ['Elsa', 3058]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id)))->toBe([]);
    }
    sl53rPianoEmesso($this, $s);
    $this->travel(1)->minutes();
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    $this->travel(1)->minutes();
    foreach ([['Ugo', 6016], ['Elsa', 1000]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id)))->toBe([]);
    }
    $this->travel(1)->minutes();

    $avviso = sl53rAvvisoInizio($s, $inizio);
    expect($avviso)->toEndWith(' I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 30,58 a credito di Ugo e € 40,58 a debito di Elsa; la parte che torna è € 90,74 a credito di Ugo e € 90,74 a debito di Elsa.'
        . sl53rFerme('€ 90,74 a credito di Ugo, € 30,58 a debito di Elsa') . ' Annullare il passaggio non li cambia: correggi dal Wallet i saldi che si modificano.');

    // Conto a mano, verso la parte che torna: la riga libera di Ugo 6016 − 6016 = 0, quella di Elsa 1000 + 5016 = 6016.
    expect(sl53Errori(sl53Annulla($this, $s, $inizio, 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -9074, 'Elsa' => 9074], $avviso))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 9074, 'Ugo' => -9074]);
});

it('la successione ad Anna e Bruno: l\'annullamento dell\'inizio seguito portando le altre righe a € 0,00, poi l\'inizio rifatto dice la parte che resta di ognuno; seguita dalla rotta della modifica, nessun rifiuto', function () {
    // La successione, le righe della fermata (€ 90,74) ferme in un piano emesso prima dell'inizio di Luca, l'inizio e le differenze, libere.
    $s = fds50Scenario([['Ugo', 'proprietario', 100]]);
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    $s['fine_ines'] = fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($this, $s, '2026-09-30');
    $this->travel(1)->minutes();
    $s['successione'] = fds50Registra($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, '2026-10-01'))['subentro'];
    $this->travel(1)->minutes();
    foreach ([['Ugo', -9074], ['Anna', 4537], ['Bruno', 4537]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['successione']->id)))->toBe([]);
    }
    sl53rFineAnno($this, $s);
    $this->travel(1)->minutes();
    $s['inizio_luca'] = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    $this->travel(1)->minutes();
    foreach (['Ugo' => 6016, 'Anna' => -3008, 'Bruno' => -3008] as $chi => $cents) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['successione']->id)))->toBe([]);
    }
    $this->travel(1)->minutes();
    $avviso = sl53rAvvisoInizio($s, $s['inizio_luca']);
    expect($avviso)->toEndWith(' I saldi del Wallet legati alla successione del 1 ottobre 2026 sommano oggi € 30,58 a credito di Ugo, € 15,29 a debito di Anna e € 15,29 a debito di Bruno; la parte che torna è € 90,74 a credito di Ugo, € 45,37 a debito di Anna e € 45,37 a debito di Bruno.'
        . SL53R_SUCC_FERME . ' Annullare il passaggio non li cambia: correggi dal Wallet i saldi che si modificano.');

    // L'avviso seguito: le righe ferme sono già la parte che torna, e le altre righe vanno a zero.
    expect(sl53Errori(sl53Annulla($this, $s, $s['inizio_luca'], 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['successione'], ['Ugo' => -9074, 'Anna' => 4537, 'Bruno' => 4537], $avviso))->toBe([]);
    expect(sl53Legate($s, $s['successione']))->toBe(['Anna' => 4537, 'Bruno' => 4537, 'Ugo' => -9074]);
    $this->travel(1)->minutes();

    $frase = sl53rFraseInizio($this, $s);
    expect($frase)->toEndWith(' I saldi del Wallet legati alla successione del 1 ottobre 2026 sommano oggi € 90,74 a credito di Ugo, € 45,37 a debito di Anna e € 45,37 a debito di Bruno; la parte che resta è € 30,58 a credito di Ugo, € 15,29 a debito di Anna e € 15,29 a debito di Bruno.'
        . SL53R_SUCC_FERME . SL53R_CORREGGI);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    // Conto a mano: Ugo −9074 + 6016 = −3058, Anna e Bruno 4537 − 3008 = 1529.
    expect(sl53SeguiIFatti($this, $s, $s['successione'], ['Ugo' => -3058, 'Anna' => 1529, 'Bruno' => 1529], $frase))->toBe([]);
    expect(sl53Legate($s, $s['successione']))->toBe(['Anna' => 1529, 'Bruno' => 1529, 'Ugo' => -3058]);
});

it('con più persone che ricevono e le altre righe a € 0,00, la parte che risale dice di correggerle dal Wallet, non di registrare saldi legati in più; seguita dalla rotta della modifica, nessun rifiuto, e le righe sommano € 61,15', function () {
    $s = sl53rSuccessioneFermeELibere($this, ['Ugo', 'Anna', 'Bruno']);
    expect(sl53SeguiIFatti($this, $s, $s['successione'], ['Ugo' => -9074, 'Anna' => 4537, 'Bruno' => 4537], 'correggi dal Wallet'))->toBe([]);
    $this->travel(1)->minutes();

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_SUCCESSIONE . SL53R_SUCC_LEGATI . '€ 90,74 a credito di Ugo, € 45,37 a debito di Anna e € 45,37 a debito di Bruno' . SL53R_SUCC_NUOVA . SL53R_SUCC_FERME . SL53R_CORREGGI);

    sl53rRegistraLaFine($this, $s);
    // Conto a mano: Ugo −9074 + 2959 = −6115, Anna 4537 − 1479 = 3058, Bruno 4537 − 1480 = 3057.
    expect(sl53SeguiIFatti($this, $s, $s['successione'], ['Ugo' => -6115, 'Anna' => 3058, 'Bruno' => 3057], $frase))->toBe([]);
    expect(sl53Legate($s, $s['successione']))->toBe(['Anna' => 3058, 'Bruno' => 3057, 'Ugo' => -6115]);
});

it('con le righe di Ugo, una ferma e una libera, già alla sua parte e quella di Elsa solo ferma, la parte che risale dice che Ugo ha già la sua parte e di registrare la differenza; seguita dalla porta, le righe sommano € 61,15', function () {
    $s = sl53rFermeELibere($this, ['Ugo']);
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6115], 'correggi dal Wallet'))->toBe([]);
    $this->travel(1)->minutes();

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_RISALE . SL53R_LEGATI . '€ 61,15 a credito di Ugo e € 90,74 a debito di Elsa' . SL53R_NUOVA . sl53rFerme('€ 90,74 a credito di Ugo, € 90,74 a debito di Elsa') . SL53R_REGISTRA);

    // Conto a mano: Elsa 9074 − 2959 = 6115 dalla porta; Ugo non cambia.
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6115, 'Elsa' => 6115], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6115, 'Ugo' => -6115]);
});

/** L'esercizio 2026 si chiude e si apre il 2027, con la stessa gestione: la pagina dei saldi mostra solo l'esercizio aperto. */
function sl53rChiudiIl2026($test, array $s): \App\Models\Esercizio
{
    $s['e']->update(['stato' => 'chiuso']);
    $nuovo = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($nuovo, $s['g']->id);

    return $nuovo;
}

it('con le righe libere di Ugo e di Elsa nell\'esercizio 2026 e il 2027 aperto, la parte che risale dice che quelle del 2026 non si modificano più e di registrare la differenza; seguita dalla porta, le righe sommano € 61,15', function () {
    $s = sl53rFermeELibere($this);
    $libere = Saldo::with('pianoRate')->where('passaggio_id', $s['vendita']->id)->get()->reject(fn (Saldo $x) => $x->eBloccato())->pluck('id')->map(fn ($id) => (int) $id)->all();
    sl53rChiudiIl2026($this, $s);
    $this->travel(1)->minutes();
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(array_intersect($libere, collect($props['immobili'])->flatMap(fn ($u) => $u['saldi'])->pluck('id')->map(fn ($id) => (int) $id)->all()))->toBe([]);

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_RISALE . SL53R_LEGATI . '€ 30,58 a credito di Ugo e € 30,58 a debito di Elsa' . SL53R_NUOVA
        . ' Non si modificano più quelli nel piano «Rate di fine anno», che non si riscrive più (€ 90,74 a credito di Ugo, € 90,74 a debito di Elsa) e quelli nell\'esercizio «Esercizio 2026», che è chiuso (€ 60,16 a debito di Ugo, € 60,16 a credito di Elsa).'
        . SL53R_REGISTRA);

    // Conto a mano: Ugo −6115 − (−3058) = −3057, Elsa 6115 − 3058 = 3057, dalla porta nel 2027.
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6115, 'Elsa' => 6115], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6115, 'Ugo' => -6115]);
});

it('con l\'altra riga di Elsa nel 2026 e una nel 2027 aperto, la parte che risale dice le due strade: la riga del 2027 si modifica; seguita, le righe sommano € 61,15', function () {
    $s = sl53rFermeELibere($this);
    sl53rChiudiIl2026($this, $s);
    $this->travel(1)->minutes();
    // Nel 2027 la porta accetta una riga legata in più per Elsa: il doppione si guarda nell'esercizio aperto.
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 100, (int) $s['vendita']->id)))->toBe([]);
    $this->travel(1)->minutes();

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_RISALE . SL53R_LEGATI . '€ 30,58 a credito di Ugo e € 31,58 a debito di Elsa' . SL53R_NUOVA
        . ' Non si modificano più quelli nel piano «Rate di fine anno», che non si riscrive più (€ 90,74 a credito di Ugo, € 90,74 a debito di Elsa) e quelli nell\'esercizio «Esercizio 2026», che è chiuso (€ 60,16 a debito di Ugo, € 60,16 a credito di Elsa).'
        . SL53R_TUTTE_E_DUE);

    // Conto a mano: la riga di Elsa del 2027 100 + 6115 − 3158 = 3057; Ugo, senza righe che si modificano, −6115 + 3058 = −3057 dalla porta.
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6115, 'Elsa' => 6115], $frase))->toBe([]);
    expect(Saldo::where('passaggio_id', $s['vendita']->id)->where('anagrafica_id', $s['persone']['Elsa']->id)->where('esercizio_id', '!=', $s['e']->id)->sole()->saldo_iniziale)->toEqual(3057);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6115, 'Ugo' => -6115]);
});

it('con le righe legate tutte libere nell\'esercizio 2026 e il 2027 aperto, la parte che risale dice che non si modificano più e di registrare la differenza; seguita dalla porta, le righe sommano € 61,15', function () {
    $s = sl53rCaso($this);
    $ids = Saldo::where('passaggio_id', $s['vendita']->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    sl53rChiudiIl2026($this, $s);
    $this->travel(1)->minutes();
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(array_intersect($ids, collect($props['immobili'])->flatMap(fn ($u) => $u['saldi'])->pluck('id')->map(fn ($id) => (int) $id)->all()))->toBe([]);

    $frase = sl53rFrase($this, $s);
    expect($frase)->toBe(SL53R_RISALE . SL53R_LEGATI . '€ 30,58 a credito di Ugo e € 30,58 a debito di Elsa' . SL53R_NUOVA
        . ' Non si modificano più quelli nell\'esercizio «Esercizio 2026», che è chiuso (€ 30,58 a credito di Ugo, € 30,58 a debito di Elsa).' . SL53R_REGISTRA);

    // Conto a mano: Ugo −6115 − (−3058) = −3057, Elsa 6115 − 3058 = 3057.
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6115, 'Elsa' => 6115], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6115, 'Ugo' => -6115]);
});

it('con le righe di Ugo, una ferma e una libera, già alla sua parte, ed Elsa senza righe, l\'inizio di Luca nomina Elsa con la sua parte; seguita dalla porta, le righe sommano € 30,58', function () {
    $s = sl53rCaso($this, legate: false);
    expect(sl53Errori(sl53Annulla($this, $s, $s['inizio_luca'], 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    $this->travel(1)->minutes();
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', -9074, (int) $s['vendita']->id)))->toBe([]);
    sl53rFineAnno($this, $s);
    $this->travel(1)->minutes();
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', 6016, (int) $s['vendita']->id)))->toBe([]);
    $this->travel(1)->minutes();

    $frase = sl53rFraseInizio($this, $s);
    expect($frase)->toBe(SL53R_INIZIO . SL53R_LEGATI . '€ 30,58 a credito di Ugo, e Elsa non ne ha' . SL53R_RESTA . sl53rFerme('€ 90,74 a credito di Ugo', una: true) . SL53R_REGISTRA);

    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -3058, 'Elsa' => 3058], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
});
