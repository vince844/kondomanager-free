<?php

/**
 * 1.11.0-beta.53 (Coda 244) — la strada a mano delle fermate indica sempre il Wallet, con i saldi legati al passaggio.
 *
 * Prima il pannello indicava il saldo manuale dal Wallet solo se nessuna delle persone della fermata aveva già un saldo su quella gestione,
 * unità ed esercizio, e altrimenti diceva «la parte si regola fra le parti: X ha già un saldo…, e il programma non ne registra un secondo».
 * Con la riga legata, che non occupa il posto, il Wallet c'è sempre: la frase dice di registrare dal Wallet i saldi legati a questo
 * passaggio, con il verso di ogni riga, e che un saldo legato si toglie se si annulla il passaggio. Il registro della fermata porta `wallet`
 * vero e `strada` = `legata`, senza l'elenco di chi ha un saldo. Fa eccezione la seconda vendita dopo una fermata (81.2), dove la parte
 * dipende da come è stata regolata la prima: lì il Wallet non si indica, come prima.
 *
 * I registri di prima (senza `strada`) si leggono come prima: lo storico del coinquilino dice la strada che il pannello aveva indicato allora.
 * Le frasi di una fermata ridotta dicono i fatti dei saldi legati alla vendita, se ci sono: quanto sommano oggi e la parte che resta di
 * ognuno; senza righe legate e con un registro nuovo non parlano di un saldo da correggere.
 *
 * Che cosa NON copre: la pagina del pannello (i suoi test leggono le frasi che il server manda); le guide (in `GuideRegolaWallet.test.ts`);
 * la regola detta altrove nel prodotto (in `Beta53FrasiRegolaWalletLegataTest`); MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Services\Riparto\AvvisoSpeseNegative;
use App\Services\Subentro\FermataDopoScrivi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PassaggiScenari.php';
require_once __DIR__ . '/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

const SL53F_STRADA = 'Se vanno a Elsa, registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: € 60,16 a credito di Ugo e € 60,16 a debito di Elsa. Un saldo legato non occupa il posto di un riporto, e si toglie se annulli il passaggio.';

it('con il riporto di Ugo il pannello della vendita indica il Wallet con i saldi legati, e il registro dice la strada legata', function () {
    $s = sl53Scenario($this, ['Ugo' => 12345]);

    $testi = fds50Testi($s['anteprima']);
    $f = $s['anteprima']['rate']['fermate_dopo_scrivi'][0] ?? [];
    $registro = fds50Fermate($s['vendita'])[0] ?? [];

    expect(collect($testi)->contains(fn (string $t) => str_contains($t, SL53F_STRADA)))->toBeTrue(implode(' | ', fds50Con($s['anteprima'], '60,16')))
        ->and(collect($testi)->contains(fn (string $t) => str_contains($t, 'il programma non ne registra un secondo')))->toBeFalse()
        ->and($f['wallet'] ?? null)->toBeTrue()
        ->and($f['strada'] ?? null)->toBe('legata')
        ->and(array_key_exists('con_un_saldo', $f))->toBeFalse()
        ->and($registro['wallet'] ?? null)->toBeTrue()
        ->and($registro['strada'] ?? null)->toBe('legata');
});

it('senza riporto la frase è la stessa: il Wallet con i saldi legati a questo passaggio', function () {
    $s = sl53Scenario($this);

    expect(collect(fds50Testi($s['anteprima']))->contains(fn (string $t) => str_contains($t, SL53F_STRADA)))->toBeTrue(implode(' | ', fds50Con($s['anteprima'], '60,16')));
});

it('la fine di un conduttore: anche con il riporto di Ugo la fermata indica il Wallet legato, e il registro conserva la strada', function () {
    $s = b51aDl7ConVendita($this);
    b51aSaldoManuale($s, 'Ugo', 5000);

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');
    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    $frasi = b51aFrasiConCifra($a, '€ 268,20');

    expect(collect($frasi)->contains(fn (string $t) => str_contains($t, 'registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: € 268,20 a credito di Ugo e € 268,20 a debito di Elsa')))->toBeTrue(implode(' | ', $frasi))
        ->and(collect($frasi)->contains(fn (string $t) => str_contains($t, 'fra le parti')))->toBeFalse(implode(' | ', $frasi))
        ->and(b51aRegistro($fine)['fermata_dopo_scrivi'][0]['strada'] ?? null)->toBe('legata');
});

it('controprova — la seconda vendita dopo una fermata (81.2) non indica il Wallet: la frase dice che il programma non sa come è stata regolata la prima (verde anche prima)', function () {
    $s = sl53Scenario($this);
    $r = fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Luca', '2026-12-01'));
    $f = collect(fds50Fermate($r['subentro']))->first(fn ($x) => isset($x['uscente_id']));

    expect($f)->not->toBeNull('La seconda vendita non ha la fermata dopo una fermata: ' . json_encode(fds50Fermate($r['subentro'])));
    expect($f['wallet'] ?? null)->toBeFalse()
        ->and(collect(fds50Testi($r['anteprima']))->contains(fn (string $t) => str_contains($t, 'Se vanno a Luca, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 1 novembre 2026, e il programma non lo sa.')))
        ->toBeTrue(implode(' | ', fds50Testi($r['anteprima'])));
});

/** L'Interno 1 di Ugo con Ines e Dora inquiline al 50 %, il preventivo generato e le rate gennaio–giugno a giornale; con il riporto di Ines (€ 50,00). */
function sl53fCoinquilino($test, bool $riporto): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    if ($riporto) {
        fclRiporto($s, 'Ines', 5000);
    }
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');

    return $s;
}

it('coinquilino che resta, con il saldo di Ines: il resto della regola del piano alla fine di Dora indica il Wallet legato, non «fra le parti»', function () {
    // Coda 245 (1.11.0-beta.55): fino alla .54 la prova era sulla fermata del coinquilino della fine di Ines (€ 90,74 rimasti nelle sue rate, «chi li
    // paga lo decidono le parti»), che non c'è più: la fine di Ines con Dora che resta ha la scelta, con la regola del piano. La stessa prova, con il
    // riporto di Ines, sta sul resto della fine di Dora dopo la fine di Ines con «Scrivi» (decisione 95.6 b): Dora − 7875 → Ugo, e restano fuori
    // Ines − 1199 e Ugo + 1199, con la strada dei saldi del Wallet legati al passaggio anche per Ines, che ha già un saldo.
    $s = sl53fCoinquilino($this, riporto: true);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-10-01', [], 'Dora'));
    $frasi = fclFrasi($a);
    $r = $a['rate']['resto_del_piano'][0] ?? [];

    expect(str_contains($frasi, 'registra dal Wallet i saldi legati a questo passaggio, sulla gestione Ordinaria 2026: € 11,99 a credito di Ines e € 11,99 a debito di Ugo'))->toBeTrue($frasi)
        ->and(str_contains($frasi, 'fra le parti: Ines ha già un saldo'))->toBeFalse($frasi)
        ->and(str_contains($frasi, 'il programma non ne registra un secondo'))->toBeFalse($frasi)
        ->and($r['wallet'] ?? null)->toBeTrue()
        ->and($r['strada'] ?? null)->toBe('legata');
});

it('lo storico del coinquilino: un registro nuovo dice la strada dei saldi legati, uno di prima la dice com\'era', function () {
    $nuovo = ['persona' => 'Ines', 'cifra' => 9074, 'gestione' => 'Ordinaria 2026', 'unita' => 'Interno 1', 'restano' => ['Dora'], 'al_posto' => ['Ugo'], 'wallet' => true, 'strada' => 'legata'];
    $diPrima = ['persona' => 'Ines', 'cifra' => 9074, 'gestione' => 'Ordinaria 2026', 'unita' => 'Interno 1', 'restano' => ['Dora'], 'al_posto' => ['Ugo'], 'wallet' => true, 'con_un_saldo' => []];

    expect(FermataDopoScrivi::fraseStoricoCoinquilino($nuovo))->toContain('la strada indicata erano i saldi dal Wallet legati a questo passaggio, sulla stessa gestione: una riga a credito di Ines e una a debito di chi li pagava')
        ->and(FermataDopoScrivi::fraseStoricoCoinquilino($diPrima))->toContain('la strada indicata era il saldo manuale dal Wallet sulla stessa gestione: una riga a credito di Ines e una a debito di chi li pagava');
});

/** Il caso INT fino alla vendita del 1/10 (fermata € 90,74 verso Elsa a carico di Ugo), senza l'inizio di Luca. */
function sl53fIntFinoAllaVendita($test): array
{
    $s = fds50Scenario([['Ugo', 'proprietario', 100]]);
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $s['fine_ines'] = fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($test, $s, '2026-09-30');
    $s['vendita'] = fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    expect(collect(fds50Fermate($s['vendita']))->map(fn ($f) => [$f['persona'], (int) $f['cifra']])->all())->toBe([['Ugo', 9074]]);

    return $s;
}

it('l\'inizio di Luca che riduce la fermata dice quanto sommano i saldi legati alla vendita, la parte che resta di ognuno e di correggerli dal Wallet', function () {
    $s = sl53fIntFinoAllaVendita($this);
    foreach ([['Ugo', -9074], ['Elsa', 9074]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id)))->toBe([]);
    }

    $a = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $testo = implode(' | ', fds50Testi($a));

    expect(str_contains($testo, 'I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 90,74 a credito di Ugo e € 90,74 a debito di Elsa; la parte che resta è € 30,58 a credito di Ugo e € 30,58 a debito di Elsa. Il programma non li cambia: correggi dal Wallet i saldi che si modificano.'))->toBeTrue($testo)
        ->and(str_contains($testo, 'correggilo: il programma non lo toglie'))->toBeFalse($testo);
});

it('senza righe legate e con il registro nuovo la frase della fermata ridotta non parla di un saldo da correggere', function () {
    $s = sl53fIntFinoAllaVendita($this);

    $a = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $testi = fds50Testi($a);

    expect($a['rate']['fermate_ridotte'] ?? [])->not->toBe([])
        ->and(collect($testi)->contains(fn (string $t) => str_contains($t, 'hai registrato il saldo dal Wallet')))->toBeFalse(implode(' | ', fds50Con($a, '90,74')));
});

it('le frasi della 31.11 (quote senza composizione con un saldo assorbito) indicano i saldi legati a questo passaggio', function () {
    $s = ruScenario('prima_rata', 30000);
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->update(['regole_calcolo' => null]);
    ruEmetti($s);

    $a = ruAnteprima($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01', 'quota' => 100,
        'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'rep. 9', 'pertinenze' => []]);
    $testo = implode(' | ', fds50Testi($a));

    expect(str_contains($testo, 'se serve un conguaglio, si scrive dal Wallet con saldi legati a questo passaggio, sulla stessa gestione'))->toBeTrue($testo)
        ->and(str_contains($testo, 'si scrive con un saldo manuale dal Wallet sulla stessa gestione'))->toBeFalse($testo);
});

it('la strada del saldo dell\'avviso delle spese negative dice la regola con i saldi legati', function () {
    expect(AvvisoSpeseNegative::STRADA_DEL_SALDO)->toContain('Il Wallet tiene un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio né quelli legati a un passaggio: dove la persona ne ha già uno');
});

/**
 * Le frasi della strada a mano che il codice dei passaggi scriveva prima del legame, e che non ci sono più: la strada «registra un saldo manuale dal
 * Wallet», il ramo «fra le parti» per chi ha già un saldo, l'invito «Toglilo dal Wallet» dell'annullamento, il conguaglio che «si scrive con un saldo
 * manuale dal Wallet» (31.11, urgenza, riga chiusa a mano, successione). Restano, per i registri di prima, le frasi al condizionale («se per
 * questo hai registrato un saldo manuale dal Wallet…»), che questa lista non nomina.
 */
const SL53F_VECCHIE = [
    'registra un saldo manuale dal Wallet',
    'il programma non ne registra un secondo',
    'Toglilo dal Wallet',
    'si scrive con un saldo manuale dal Wallet',
    'si regola con un saldo manuale dal Wallet',
    'se serve, con un saldo manuale dal Wallet',
    'la strada: un saldo manuale dal Wallet',
];

it('nessuna frase dei servizi e delle azioni dei passaggi indica più la strada a mano senza il legame', function () {
    $trovate = [];
    foreach ([...glob(base_path('app/Services/Subentro/*.php')), ...glob(base_path('app/Actions/Subentro/*.php'))] as $file) {
        foreach (token_get_all(file_get_contents($file)) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $stringa = preg_replace('/\s+/u', ' ', str_replace("\\'", "'", substr($token[1], 1, -1)));
            foreach (SL53F_VECCHIE as $vecchia) {
                if (str_contains($stringa, $vecchia)) {
                    $trovate[] = basename($file) . ': «' . $vecchia . '» in «' . mb_substr($stringa, 0, 160) . '…»';
                }
            }
        }
    }

    expect($trovate)->toBe([], count($trovate) . " frasi della strada senza il legame:\n" . implode("\n", $trovate));
});

it('controprova — la lettura delle stringhe trova la frase vecchia in un file costruito apposta, e non quella al condizionale dei registri di prima', function () {
    $base = tempnam(sys_get_temp_dir(), 'sl53f');
    $file = $base . '.php';
    file_put_contents($file, "<?php\n\$a = 'Se vanno a Elsa, registra un saldo manuale dal Wallet sulla stessa gestione';\n\$b = 'se per questo hai registrato un saldo manuale dal Wallet (€ 1,00), rivedilo';\n");
    $stringhe = [];
    foreach (token_get_all(file_get_contents($file)) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $stringhe[] = substr($token[1], 1, -1);
        }
    }
    @unlink($file);
    @unlink($base);

    expect(array_values(array_filter($stringhe, fn ($s) => collect(SL53F_VECCHIE)->contains(fn ($v) => str_contains($s, $v)))))->toHaveCount(1);
});
