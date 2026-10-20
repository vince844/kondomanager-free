<?php

/**
 * 1.11.0-beta.54 (Coda 341) — la fermata dell'erede con l'arretrato agli eredi che aveva anche comprato una parte.
 *
 * Ugo 75 % e Anna 25 %, la fine di Ines il 1/7 con «Scrivi» (la coppia 18148: Ugo 13611, Anna 4537). Anna vende a Bruno il 1/10 (la sua parte, € 22,68,
 * resta a carico di Anna). Ugo muore il 15/10, eredi Bruno ed Elsa a metà, con l'arretrato agli eredi: la riga della coppia di Ugo passa agli eredi
 * (Bruno 6806, Elsa 6805, decisione 65). Bruno rivende tutto (62,5 %) a Carlo il 1/12. Bruno ha due parti: quella avuta con la successione (37,5 %, la
 * sua riga della coppia) e quella comprata da Anna (25 %, la cui fermata è a carico di Anna). Prima il ramo dell'erede veniva prima della catena dei
 * passaggi della sua riga: la fermata era «a carico di Bruno» per tutta la cifra, con il Wallet. Ora la catena si legge per prima: se in catena ci sono
 * fermate di altri passaggi la fermata è a carico di tutti insieme, nella forma che la .53 usa già per più origini, e il programma non indica il Wallet
 * perché non sa di chi è la parte. L'erede senza acquisto resta com'è (a suo carico, con il Wallet e la strada dei saldi legati).
 *
 * Corregge anche un difetto vicino della .53: dopo una fermata a carico di più persone la vendita successiva nomina quelle della fermata e non tutta la
 * coppia (la terza vendita senza eredi). Le frasi dei saldi legati leggono l'erede due volte, fra le persone a carico e come chi è uscito: le righe di Bruno si
 * sommano una volta sola e chi ha già una riga legata non è mandato a registrarne un'altra (le righe legate alla rivendita).
 *
 * Le persone di una fermata di prima si leggono come le righe della coppia: chi è morto con l'arretrato agli eredi ha lasciato loro la sua riga, e con lei la
 * fermata che aveva. Su un'unità mista (Ugo proprietario pieno della metà A e nudo proprietario della metà B, Zeno usufruttuario della metà B: la coppia è Ugo 9074
 * e Zeno 9074) Ugo vende la metà piena a Bruno (1/10, € 45,37 a carico di Ugo), muore il 15/10 e la nuda va ai suoi eredi: Bruno, o Bruno ed Elsa, o Bruno e Dora se
 * a comprare era Elsa. La rivendita a Carlo (1/12) vale 18148 × 50 × 31/18400 = 1528,77 → € 15,29, a carico degli eredi di Ugo, e Zeno, che ha la sua riga ma non
 * c'entra con quella fermata, non si nomina. Se la fermata di prima era di una persona viva (Anna) la cifra resta a lei, e chi ha ereditato su un'altra riga non è a
 * carico per questa. Con più acquisti la frase dell'erede dice le fermate di prima una per una, perché fra le persone a carico ci sono anche chi ha venduto, e il
 * motivo non dice che nulla gli era passato. Lo storico della prima vendita non dice una parte passata quando chi rivende ha anche una parte ereditata: la cifra
 * della rivendita viene da più origini che il programma non divide.
 *
 * Fonte dell'atteso: il conto a mano. Pesi sui 184 giorni (Bruno 62,5 % dal 15/10 al 30/11 e Carlo dal 1/12): 18148 × 62,5 × 31/(100 × 184) = 1910,96 →
 * 1911; per origine 1146,58 (dalla successione) e 764,39 (dalla vendita di Anna). L'erede senza acquisto: 18148 × 37,5 × 31/(100 × 184) = 1146,58 → 1147.
 * Con l'inizio di Luca il 15/12 restano i 14 giorni 1/12–14/12: 18148 × 62,5 × 14/(100 × 184) = 863,02 → 8,63. La terza vendita (Carlo a Dora il 15/12,
 * 17 giorni): 18148 × 62,5 × 17/(100 × 184) = 1047,95 → 1048. La terza vendita senza eredi: Ugo 50, Anna 25, Bruno 25; Elsa compra da Anna (1/10) e da Ugo (15/10) e
 * rivende a Carlo (1/12, 75 % per 31 giorni: 2293,16 → 2293), Carlo rivende a Dora (15/12, 17 giorni: 1257,54 → 1258).
 *
 * Che cosa NON copre: la divisione della cifra fra l'erede e chi gli ha venduto (€ 11,47 e € 7,64): è una scelta delle parti, e la frase non la divide;
 * più di due vendite dopo l'erede (l'alternativa nomina l'ultima fermata); la parte della cifra che sta nella riga di un altro titolare della coppia e che un
 * passaggio di prima aveva fermato a suo carico (sull'unità mista con l'estinzione dell'usufrutto di Zeno, € 15,29 di € 30,58 stanno nella riga di Zeno, e
 * l'estinzione li aveva fermati a carico di Zeno: la frase li mette a carico di Bruno per intero, ed è la Coda 346); MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Services\Subentro\FermataDopoScrivi;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PassaggiScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

/** La base: i titolari dati, la fine di Ines il 1/7 con «Scrivi», emesso fino al 30/9. Carlo e Dora sono nel condominio. */
function fer54Base($test, array $titolari): array
{
    $s = fds50Scenario($titolari);
    foreach (['Carlo', 'Dora'] as $nome) {
        isset($s['persone'][$nome]) || fclPersona($s, $nome);
    }
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $s['fine'] = fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($test, $s, '2026-09-30');
    $test->travel(1)->minutes();

    return $s;
}

/** Un passaggio dalle rotte vere, e un minuto dopo. */
function fer54Passo($test, array $s, array $dati): array
{
    $r = fds50Registra($test, $s, $dati);
    $test->travel(1)->minutes();

    return $r;
}

/** La successione di Ugo (quota data) a Bruno ed Elsa a metà, con l'arretrato agli eredi. */
function fer54Successione(array $s, string $il, float $quota): array
{
    return ['tipo' => 'successione', 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, 'Ugo', 'proprietario'), 'decorrenza' => $il, 'quota' => $quota,
        'eredi' => [['anagrafica_id' => $s['persone']['Bruno']->id, 'quota' => $quota / 2], ['anagrafica_id' => $s['persone']['Elsa']->id, 'quota' => $quota / 2]],
        'arretrato' => Subentro::ARRETRATO_AGLI_EREDI, 'erede_di_riferimento' => $s['persone']['Bruno']->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 77', 'pertinenze' => []];
}

/** Ugo 75 % e Anna 25 %: Anna vende a Bruno il 1/10, Ugo muore il 15/10 (eredi Bruno ed Elsa), Bruno rivende tutto a Carlo il 1/12; con `$legate` le righe del Wallet della rivendita. */
function fer54FinoAllaRivendita($test, bool $legate = false, bool $regolataLaPrima = false): array
{
    $s = fer54Base($test, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    $s['p1'] = fer54Passo($test, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-10-01', 25))['subentro'];
    if ($regolataLaPrima) {
        foreach ([['Anna', -2268], ['Bruno', 2268]] as [$chi, $cents]) {
            expect(sl53Errori(sl53Posta($test, $s, $chi, $cents, (int) $s['p1']->id)))->toBe([]);
        }
        $test->travel(1)->minutes();
    }
    $s['p2'] = fer54Passo($test, $s, fer54Successione($s, '2026-10-15', 75))['subentro'];
    $s['pannello'] = fds50Anteprima($test, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 62.5));
    $s['p3'] = fer54Passo($test, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 62.5))['subentro'];
    if ($legate) {
        foreach ([['Bruno', -1911], ['Carlo', 1911]] as [$chi, $cents]) {
            expect(sl53Errori(sl53Posta($test, $s, $chi, $cents, (int) $s['p3']->id)))->toBe([]);
        }
        $test->travel(1)->minutes();
    }

    return $s;
}

/** La frase del pannello che nomina una cifra: la prima stringa con il testo dato. */
function fer54Frase(array $anteprima, string $con): string
{
    return (string) collect(fds50Testi($anteprima))->first(fn (string $t) => str_contains($t, $con));
}

/** Le chiavi della fermata che il registro conserva, per il confronto: a carico di chi, la cifra, chi riceve, chi esce e la fermata di prima. */
function fer54Registro(Subentro $passaggio): array
{
    return array_map(fn (array $f) => [
        'persone' => $f['persone'] ?? [$f['persona']], 'cifra' => (int) $f['cifra'], 'riceve' => collect($f['parti'])->mapWithKeys(fn ($p) => [$p['nome'] => (int) $p['cifra']])->all(),
        'uscente' => $f['uscente'] ?? null, 'dopo_la_fermata' => $f['dopo_la_fermata'] ?? null, 'wallet' => (bool) ($f['wallet'] ?? false), 'strada' => $f['strada'] ?? null,
    ], fds50Fermate($passaggio->fresh()));
}

const FER54_DOPO_LA_VENDITA_DI_ANNA = ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 2268, 'persona' => 'Anna'];
/** L'alternativa della fermata: a carico di Bruno e Anna, o di Bruno se la fermata della vendita del 1/10 è già regolata. */
const FER54_ALTERNATIVA = 'a carico di Bruno e Anna, o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata';

it('Coda 341 — Bruno compra il 25 % di Anna, eredita da Ugo con l\'arretrato agli eredi e rivende tutto a Carlo: € 19,11 a carico di Bruno e Anna insieme, o di Bruno se la fermata della vendita è regolata; niente Wallet', function () {
    $s = fer54FinoAllaRivendita($this);

    // Conto a mano: 1911 in tutto; la fermata di Anna del 1/10 (€ 22,68, a suo carico) è nella catena di Bruno, quindi la cifra è di chi ha le due origini insieme.
    expect(fer54Registro($s['p3']))->toBe([[
        'persone' => ['Bruno', 'Anna'], 'cifra' => 1911, 'riceve' => ['Carlo' => 1911], 'uscente' => 'Bruno', 'dopo_la_fermata' => FER54_DOPO_LA_VENDITA_DI_ANNA, 'wallet' => false, 'strada' => null,
    ]]);
    $f = fds50Fermate($s['p3']->fresh())[0];
    expect($f['ereditata']['passaggio'] ?? null)->toBe('la successione del 15 ottobre 2026')
        ->and($f['ereditata']['da'] ?? null)->toBe('Ugo')
        ->and($f['ereditata']['sua'] ?? null)->toBe(6806);

    // Il pannello: la parte avuta con la successione, la fermata della vendita di Anna, e la cifra a carico di tutti e due.
    $frase = fer54Frase($s['pannello'], 'Con questo passaggio');
    expect($frase)->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 136,11 sulla gestione Ordinaria 2026 (Interno 1), '
        . 'e con la successione del 15 ottobre 2026 una parte, € 68,06, è passata a Bruno. '
        . 'La vendita del 1 ottobre 2026 non ha spostato la parte di Bruno, € 22,68, rimasta a carico di Anna da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 19,11 restano a carico di Bruno e Anna, '
        . 'o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 1 ottobre 2026, e il programma non lo sa.');
    expect($frase)->not->toContain('registra dal Wallet');

    // Il motivo del cancello, lo storico e l'avviso di «Annulla il conguaglio» dicono la stessa alternativa.
    expect(fds50Testi($s['pannello']))->toContain('€ 19,11 dei giorni dopo l\'uscita di Ines restano ' . FER54_ALTERNATIVA . ' (gestione Ordinaria 2026): '
        . 'il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Carlo con questo passaggio, come non era passato a Bruno con la vendita del 1 ottobre 2026');
    $avviso = (string) collect(FermataDopoScrivi::avvisiDelConguaglio($s['fine']->fresh()))->first(fn (string $t) => str_contains($t, '19,11'));
    expect($avviso)->toBe('La vendita del 1 dicembre 2026 si è fermata su questo conguaglio: € 19,11 dei giorni dal 1 dicembre 2026 sono rimasti a carico di Bruno e Anna, '
        . 'o di Bruno se la fermata della vendita del 1 ottobre 2026 era già stata regolata, sulla gestione Ordinaria 2026, da regolare a mano con Carlo. '
        . 'Annullato il conguaglio, quella fermata non vale più: se le parti l\'hanno regolata fra loro, quell\'accordo va rivisto.');
    $storico = (string) collect(fds50Testi(fds50Storico($s, $s['p3'])))->first(fn (string $t) => str_starts_with($t, 'Il conguaglio scritto alla fine locazione di Ines'));
    expect($storico)->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 19,11 dei giorni dal 1 dicembre 2026 sono rimasti '
        . 'a carico di Bruno e Anna sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Carlo, '
        . 'o fra Bruno e Carlo se la fermata della vendita del 1 ottobre 2026 era già stata regolata.');
    fer54SenzaNomiMancanti($s['pannello']);
});

it('Coda 341 (guardia) — l\'erede che non ha comprato niente rivende i suoi 37,5 %: € 11,47 a carico di Bruno, con il Wallet e la strada dei saldi legati; «Annulla il conguaglio» e l\'inizio di Luca la leggono come ogni fermata della .53', function () {
    // Il caso che la Coda 341 non cambia, e che nessun test fissava: la fermata del ramo dell'erede scrive nel registro la strada dei saldi legati.
    $s = fer54Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer54Passo($this, $s, fer54Successione($s, '2026-10-15', 75));
    $p3 = fer54Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 37.5))['subentro'];

    // Conto a mano: 18148 × 37,5 × 31/(100 × 184) = 1146,58 → 1147, a carico di Bruno, la sua parte della coppia di Ugo (decisione 65).
    $f = fds50Fermate($p3->fresh());
    expect($f)->toHaveCount(1)
        ->and($f[0]['cifra'])->toBe(1147)
        ->and($f[0]['persona'])->toBe('Bruno')
        ->and($f[0]['ereditata']['passaggio'] ?? null)->toBe('la successione del 15 ottobre 2026')
        ->and($f[0]['wallet'])->toBeTrue()
        ->and($f[0]['strada'] ?? null)->toBe(FermataDopoScrivi::STRADA_LEGATA);

    // «Annulla il conguaglio» della fine di Ines: senza saldi legati, la frase della .53 (l'accordo fra le parti), non quella del saldo manuale di prima.
    $avviso = (string) collect(FermataDopoScrivi::avvisiDelConguaglio($s['fine']->fresh()))->first(fn (string $t) => str_contains($t, '11,47'));
    expect($avviso)->toContain('se le parti l\'hanno regolata fra loro, quell\'accordo va rivisto')
        ->and($avviso)->not->toContain('saldo manuale dal Wallet');

    // L'inizio di Luca il 15/12 riduce la fermata: nessun «saldo registrato dal Wallet» di prima da correggere.
    $ridotte = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'))['rate']['fermate_ridotte'] ?? [];
    expect($ridotte)->toHaveCount(1)
        ->and($ridotte[0]['dal_wallet'])->toBe([])
        ->and(implode(' | ', fds50Con(['r' => $ridotte], 'Wallet')))->not->toContain('Se per quella fermata hai registrato il saldo dal Wallet');
});

it('Coda 341 (fermata di Anna regolata) — la fermata della vendita del 1/10 regolata con i saldi legati (Anna a credito, Bruno a debito): la fermata di Bruno dice la stessa alternativa, perché il programma non sa quale delle due è la parte regolata', function () {
    $s = fer54FinoAllaRivendita($this, regolataLaPrima: true);

    expect(fer54Registro($s['p3']))->toBe([[
        'persone' => ['Bruno', 'Anna'], 'cifra' => 1911, 'riceve' => ['Carlo' => 1911], 'uscente' => 'Bruno', 'dopo_la_fermata' => FER54_DOPO_LA_VENDITA_DI_ANNA, 'wallet' => false, 'strada' => null,
    ]]);
    expect(fer54Frase($s['pannello'], 'Con questo passaggio'))->toContain('€ 19,11 restano a carico di Bruno e Anna, o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata.');
});

it('Coda 341 (acquisto dopo l\'eredità) — Ugo muore il 1/10, Anna vende a Bruno il 15/10, Bruno rivende tutto il 1/12: la stessa fermata di € 19,11, e l\'alternativa nomina la vendita del 15/10', function () {
    $s = fer54Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer54Passo($this, $s, fer54Successione($s, '2026-10-01', 75));
    $vendita = fer54Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-10-15', 25))['subentro'];
    // Conto a mano: la vendita di Anna del 15/10 ferma 4537 × 78/184 = 1923,29 → € 19,23, a carico di Anna.
    expect(fer54Registro($vendita))->toBe([['persone' => ['Anna'], 'cifra' => 1923, 'riceve' => ['Bruno' => 1923], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 62.5));
    $p3 = fer54Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 62.5))['subentro'];

    expect(fer54Registro($p3))->toBe([[
        'persone' => ['Bruno', 'Anna'], 'cifra' => 1911, 'riceve' => ['Carlo' => 1911], 'uscente' => 'Bruno',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 15 ottobre 2026', 'cifra' => 1923, 'persona' => 'Anna'], 'wallet' => false, 'strada' => null,
    ]]);
    expect(fer54Frase($pannello, 'Con questo passaggio'))->toContain('e con la successione del 1 ottobre 2026 una parte, € 68,06, è passata a Bruno. '
        . 'La vendita del 15 ottobre 2026 non ha spostato la parte di Bruno, € 19,23, rimasta a carico di Anna da regolare a mano.')
        ->and(fer54Frase($pannello, 'Con questo passaggio'))->toContain('€ 19,11 restano a carico di Bruno e Anna, o di Bruno se la fermata della vendita del 15 ottobre 2026 è già stata regolata.');
    fer54SenzaNomiMancanti($pannello);
});

it('Coda 341 (inizio dopo la rivendita) — dopo la rivendita di Bruno, l\'inizio di Luca il 15/12 riduce la fermata a € 8,63, sempre a carico di Bruno e Anna, o di Bruno', function () {
    $s = fer54FinoAllaRivendita($this);
    $inizio = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    // Conto a mano: restano i 14 giorni 1/12–14/12: 18148 × 62,5 × 14/(100 × 184) = 863,02 → € 8,63; Luca rimborsa gli altri 17 giorni (€ 10,48).
    expect(fer54Frase($inizio, 'Con questo inizio'))->toBe('La vendita del 1 dicembre 2026 ha lasciato a carico di Bruno e Anna, o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata, '
        . '€ 19,11 dei giorni dopo l\'uscita di Ines, da regolare a mano con Carlo. '
        . 'Con questo inizio i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori: resta da regolare € 8,63 con Carlo. '
        . 'Se chi ne risponde e Carlo hanno già regolato € 19,11, € 10,48 vanno restituiti a Carlo.');
    $ridotte = $inizio['rate']['fermate_ridotte'] ?? [];
    expect($ridotte)->toHaveCount(1)
        ->and($ridotte[0]['persone'] ?? null)->toBe(['Bruno', 'Anna'])
        ->and($ridotte[0]['residuo'] ?? null)->toBe(863);
});

it('Coda 341 (terza vendita) — Carlo rivende a Dora il 15/12, dopo la rivendita di Bruno: € 10,48 a carico di Bruno e Anna, o di Carlo se la fermata della vendita del 1/12 è regolata', function () {
    $s = fer54FinoAllaRivendita($this);
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Carlo', 'Dora', '2026-12-15', 62.5));

    // Conto a mano: 18148 × 62,5 × 17/(100 × 184) = 1047,95 → 1048, dalla fermata di Bruno e Anna (1911) a Carlo.
    $f = $pannello['rate']['fermate_dopo_scrivi'] ?? [];
    expect(array_map(fn (array $x) => [$x['persone'] ?? [$x['persona']], (int) $x['cifra'], $x['uscente'] ?? null, $x['dopo_la_fermata'] ?? null], $f))
        ->toBe([[['Bruno', 'Anna'], 1048, 'Carlo', ['passaggio' => 'la vendita del 1 dicembre 2026', 'cifra' => 1911]]]);
    expect(fer54Frase($pannello, 'Con questo passaggio'))->toContain('€ 10,48 restano a carico di Bruno e Anna, o di Carlo se la fermata della vendita del 1 dicembre 2026 è già stata regolata.');
    fer54SenzaNomiMancanti($pannello);
});

it('Coda 341 (righe legate) — con le righe del Wallet legate alla rivendita (Bruno a credito di € 19,11, Carlo a debito), l\'inizio di Luca dice i fatti senza contare due volte le righe di Bruno, e seguiti i fatti nessuna rotta rifiuta', function () {
    $s = fer54FinoAllaRivendita($this, legate: true);
    $inizio = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));
    $frase = fer54Frase($inizio, 'Con questo inizio');

    // Le righe legate sommano € 19,11 a credito di Bruno e a debito di Carlo (non € 38,22: Bruno è fra le persone a carico e chi è uscito).
    expect($frase)->toContain('I saldi del Wallet legati alla vendita del 1 dicembre 2026 sommano oggi € 19,11 a credito di Bruno e € 19,11 a debito di Carlo, e Anna non ne ha; '
        . 'la parte che resta è € 8,63 a debito di Carlo e € 8,63 a credito di Bruno e Anna insieme, o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata. '
        . 'Il programma non li cambia: correggi dal Wallet i saldi che si modificano, e per chi non ne ha uno registra la differenza con un saldo legato alla vendita del 1 dicembre 2026.')
        ->and($frase)->not->toContain('€ 38,22');

    // Seguiti i fatti con la parte a Bruno (la fermata della vendita di Anna regolata): la riga di Bruno da −1911 a −863, quella di Carlo da 1911 a 863.
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['p3'], ['Bruno' => -863, 'Carlo' => 863], $frase))->toBe([]);
    expect(sl53Legate($s, $s['p3']))->toBe(['Bruno' => -863, 'Carlo' => 863]);
});

it('Coda 341 (righe legate a tutti) — Bruno, Anna e Carlo hanno ognuno una riga legata alla rivendita: la frase somma le righe una volta sola e non manda a registrare un saldo a chi ne ha già uno', function () {
    $s = fer54FinoAllaRivendita($this);
    // Bruno −€ 14,11 e Anna −€ 5,00 (insieme € 19,11 a credito), Carlo € 19,11 a debito.
    foreach ([['Bruno', -1411], ['Anna', -500], ['Carlo', 1911]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['p3']->id)))->toBe([]);
    }
    $this->travel(1)->minutes();
    $frase = fer54Frase(fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15')), 'Con questo inizio');

    // Ognuno ha una riga che si modifica: la strada è correggere dal Wallet, e nessuno è mandato a registrare un saldo nuovo.
    expect($frase)->toContain('I saldi del Wallet legati alla vendita del 1 dicembre 2026 sommano oggi € 14,11 a credito di Bruno, € 5,00 a credito di Anna e € 19,11 a debito di Carlo; '
        . 'la parte che resta è € 8,63 a debito di Carlo e € 8,63 a credito di Bruno e Anna insieme, o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata. '
        . 'Il programma non li cambia: correggi dal Wallet i saldi che si modificano.')
        ->and($frase)->not->toContain('per chi non ne ha uno');

    // Seguiti i fatti con la parte a Bruno (Anna a zero): le tre righe si correggono, nessuna rotta rifiuta.
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['p3'], ['Bruno' => -863, 'Anna' => 0, 'Carlo' => 863], $frase))->toBe([]);
    expect(sl53Legate($s, $s['p3']))->toBe(['Anna' => 0, 'Bruno' => -863, 'Carlo' => 863]);
});

it('Coda 341 (annullamento) — l\'annullamento della rivendita di Bruno con le righe legate: le righe si tolgono con il passaggio', function () {
    $s = fer54FinoAllaRivendita($this, legate: true);
    $r = sl53Annulla($this, $s, $s['p3']);

    expect(sl53Errori($r))->toBe([])
        ->and(sl53Messaggio($r)['message'])->toContain('I saldi del Wallet legati a questo passaggio sono stati tolti: € 19,11 a credito di Bruno e € 19,11 a debito di Carlo (gestione Ordinaria 2026).')
        ->and(sl53Legate($s, $s['p3']))->toBe([]);
});

it('Coda 341 (fine dopo la rivendita) — l\'inizio di Luca il 5/12 e la sua fine il 20/12 dopo la rivendita di Bruno: la parte che risale dice da quanto a quanto, senza nominare chi ne risponde', function () {
    $s = fer54FinoAllaRivendita($this);
    fer54Passo($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-05'));
    $fine = fds50Anteprima($this, $s, b51aIntFine($s, '2026-12-20'));

    // Conto a mano: dopo l'inizio del 5/12 restano i 4 giorni 1/12–4/12: 18148 × 62,5 × 4/18400 = 246,58 → € 2,47. Con la fine del 20/12 tornano anche i 12 giorni dal
    // 20/12 al 31/12, 16 in tutto: 18148 × 62,5 × 16/18400 = 986,30 → € 9,86. La frase dice le due cifre e non nomina chi ne risponde.
    expect(fer54Frase($fine, 'risale'))->toContain('la parte verso Carlo della fermata della vendita del 1 dicembre 2026 risale da € 2,47 a € 9,86 (gestione Ordinaria 2026)');
});

it('Coda 341 (terza vendita senza eredi) — Ugo 50, Anna 25, Bruno 25; Elsa compra da Anna (1/10) e da Ugo (15/10), rivende a Carlo (1/12), Carlo rivende a Dora (15/12): la terza vendita è a carico di Ugo e Anna, non anche di Bruno', function () {
    $s = fer54Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 25], ['Bruno', 'proprietario', 25]]);
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-15', 50));
    $p3 = fer54Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-12-01', 75))['subentro'];
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Carlo', 'Dora', '2026-12-15', 75));

    // Conto a mano: la seconda vendita (Elsa a Carlo, 75 % per 31 giorni) ferma 18148 × 75 × 31/18400 = 2293,16 → 2293 a carico di Ugo e Anna, che sono le persone
    // delle due fermate di Elsa; Bruno non c'entra. La terza (Carlo a Dora, 17 giorni) 18148 × 75 × 17/18400 = 1257,54 → 1258, a carico di Ugo e Anna, o di Carlo.
    expect(fer54Registro($p3))->toBe([[
        'persone' => ['Ugo', 'Anna'], 'cifra' => 2293, 'riceve' => ['Carlo' => 2293], 'uscente' => 'Elsa', 'dopo_la_fermata' => null, 'wallet' => false, 'strada' => null,
    ]]);
    $f = $pannello['rate']['fermate_dopo_scrivi'] ?? [];
    expect(array_map(fn (array $x) => [$x['persone'] ?? [$x['persona']], (int) $x['cifra'], $x['uscente'] ?? null, $x['dopo_la_fermata'] ?? null], $f))
        ->toBe([[['Ugo', 'Anna'], 1258, 'Carlo', ['passaggio' => 'la vendita del 1 dicembre 2026', 'cifra' => 2293]]]);
    expect(fer54Frase($pannello, 'Con questo passaggio'))->toContain('€ 12,58 restano a carico di Ugo e Anna, o di Carlo se la fermata della vendita del 1 dicembre 2026 è già stata regolata.')
        ->and(fer54Frase($pannello, 'Con questo passaggio'))->not->toContain('Bruno');
    fer54SenzaNomiMancanti($pannello);
});

it('Coda 341 (più acquisti senza eredi, controprova) — Ugo 50, Anna 25, Bruno 25; Elsa compra da Anna e da Ugo e rivende il 75 % a Carlo: il motivo dice ancora «come non era passato a Elsa», perché a lei nulla era passato', function () {
    $s = fer54Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 25], ['Bruno', 'proprietario', 25]]);
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-15', 50));
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-12-01', 75));

    // Conto a mano: 18148 × 75 × 31/18400 = 2293,16 → € 22,93, a carico di Ugo e Anna, che sono le persone delle due fermate di Elsa.
    expect(fds50Testi($pannello))->toContain('€ 22,93 dei giorni dopo l\'uscita di Ines restano a carico di Ugo e Anna, o di Elsa se la parte di Elsa è già stata regolata (gestione Ordinaria 2026): '
        . 'il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Carlo con questo passaggio, come non era passato a Elsa');
});

it('Coda 341 (più acquisti) — Bruno compra da Anna (1/10) e da Carlo (5/10), eredita da Ugo (15/10) e rivende il 75 % a Dora (1/12): la frase dice le due fermate di prima e di chi sono rimaste, e il motivo non dice che nulla gli era passato', function () {
    $s = fer54Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 25], ['Carlo', 'proprietario', 25]]);
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-10-01', 25));
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Carlo', 'Bruno', '2026-10-05', 25));
    fer54Passo($this, $s, fer54Successione($s, '2026-10-15', 50));
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Dora', '2026-12-01', 75));
    $p3 = fer54Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Dora', '2026-12-01', 75))['subentro'];

    // Conto a mano: la vendita di Anna (1/10) ferma 4537 × 92/184 = 2268,5 → € 22,68 (il centesimo a pari resto resta a chi esce), a carico di Anna; la vendita di Carlo (5/10)
    // 4537 × 88/184 = 2169,87 → € 21,70, a carico di Carlo. La riga di Ugo (9074) passa a Bruno ed Elsa, 4537 ciascuno. Bruno ha il 75 % (25 + 25 comprati e 25 ereditati), e la
    // rivendita a Dora vale 18148 × 75 × 31/18400 = 2293,16 → € 22,93, a carico di Anna, Carlo e Bruno insieme: il programma non divide la cifra per origine.
    expect(fer54Registro($p3))->toBe([[
        'persone' => ['Anna', 'Carlo', 'Bruno'], 'cifra' => 2293, 'riceve' => ['Dora' => 2293], 'uscente' => 'Bruno', 'dopo_la_fermata' => null, 'wallet' => false, 'strada' => null,
    ]]);
    expect(fer54Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1), '
        . 'e con la successione del 15 ottobre 2026 una parte, € 45,37, è passata a Bruno. '
        . 'La vendita del 1 ottobre 2026 non ha spostato la parte di Bruno, € 22,68, rimasta a carico di Anna da regolare a mano. '
        . 'La vendita del 5 ottobre 2026 non ha spostato la parte di Bruno, € 21,70, rimasta a carico di Carlo da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Dora, ma il programma non la sposta: € 22,93 restano a carico di Anna, Carlo e Bruno, '
        . 'o di Bruno se la parte di Bruno è già stata regolata. '
        . 'Se vanno a Dora, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la parte di Bruno, e il programma non lo sa.');
    // Il motivo del cancello non dice «come non era passato a Bruno»: la parte avuta con la successione gli è passata, sono le parti comprate che no.
    expect(fds50Testi($pannello))->toContain('€ 22,93 dei giorni dopo l\'uscita di Ines restano a carico di Anna, Carlo e Bruno, o di Bruno se la parte di Bruno è già stata regolata (gestione Ordinaria 2026): '
        . 'il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Dora con questo passaggio, come non erano passate a Bruno le parti che aveva comprato');
    foreach (fds50Con($pannello, 'non passa a Dora') as $motivo) {
        expect($motivo)->not->toContain('come non era passato a Bruno');
    }
    fer54SenzaNomiMancanti($pannello);
});

/** Il testo dello storico di una fermata: la voce del passaggio che comincia con il conguaglio della fine di Ines. */
function fer54Storico(array $s, Subentro $passaggio): string
{
    return (string) collect(fds50Testi(fds50Storico($s, $passaggio)))->first(fn (string $t) => str_starts_with($t, 'Il conguaglio scritto alla fine locazione di Ines'));
}

it('Coda 341 (storico della prima vendita) — dopo la rivendita di Bruno, lo storico della vendita di Anna dice i € 22,68 rimasti a carico di Anna e non una parte passata a Carlo: la cifra della rivendita viene anche dalla successione', function () {
    $s = fer54FinoAllaRivendita($this);

    // Conto a mano: la rivendita di Bruno vale 1911, di cui 1146,58 dalla parte avuta con la successione e 764,39 da quella comprata da Anna. Dei € 22,68 della vendita di
    // Anna ne sono passati € 7,64, ma il programma non divide la cifra della rivendita fra le due origini: dire «di questi, € 19,11 sono passati a Carlo» sarebbe falso.
    expect(fer54Storico($s, $s['p1']))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 22,68 dei giorni dal 1 ottobre 2026 '
        . 'sono rimasti a carico di Anna sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Bruno.');
    expect(FermataDopoScrivi::passateDopo($s['p1']->fresh(), fds50Fermate($s['p1'])[0]))->toBe([]);

    // La terza vendita (Carlo a Dora, 15/12) viene dalla fermata di Bruno e Anna, che non è quella di un erede: lì la parte passata si sa, 18148 × 62,5 × 17/18400 = 1047,95 → € 10,48.
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Carlo', 'Dora', '2026-12-15', 62.5));
    expect(fer54Storico($s, $s['p3']))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 19,11 dei giorni dal 1 dicembre 2026 sono rimasti '
        . 'a carico di Bruno e Anna sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Carlo, o fra Bruno e Carlo se la fermata della vendita del 1 ottobre 2026 era già stata regolata; '
        . 'di questi, € 10,48 sono passati a Dora con la vendita del 15 dicembre 2026.');
    expect(fer54Storico($s, $s['p1']))->not->toContain('di questi');
});

it('Coda 341 (storico, acquisto dopo l\'eredità) — Ugo muore il 1/10, Anna vende a Bruno il 15/10, Bruno rivende tutto il 1/12: lo storico della vendita del 15/10 dice i € 19,23 rimasti a carico di Anna, senza una parte passata a Carlo', function () {
    $s = fer54Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer54Passo($this, $s, fer54Successione($s, '2026-10-01', 75));
    $vendita = fer54Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-10-15', 25))['subentro'];
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 62.5));

    // Conto a mano: la vendita di Anna del 15/10 ferma 4537 × 78/184 = 1923,29 → € 19,23; la rivendita di Bruno vale 1911, di cui solo 764 dalla parte comprata da Anna.
    expect(fer54Storico($s, $vendita))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 19,23 dei giorni dal 15 ottobre 2026 '
        . 'sono rimasti a carico di Anna sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Bruno.');
});

it('Coda 341 (storico, senza eredi) — controprova: Elsa compra il 25 % di Anna e lo rivende a Carlo, e lo storico della vendita di Anna dice ancora «di questi, € 7,64 sono passati a Carlo»', function () {
    $s = fer54Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    $vendita = fer54Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25))['subentro'];
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-12-01', 25));

    // Conto a mano: tutta la cifra della rivendita (18148 × 25 × 31/18400 = 764,39 → € 7,64) viene dalla parte comprata da Anna: lì la parte passata si sa.
    expect(fer54Storico($s, $vendita))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 22,68 dei giorni dal 1 ottobre 2026 '
        . 'sono rimasti a carico di Anna sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Elsa; di questi, € 7,64 sono passati a Carlo con la vendita del 1 dicembre 2026.');
});

/** L'unità mista: Ugo proprietario pieno della metà A e nudo proprietario della metà B, Zeno usufruttuario della metà B (la fine di Ines: coppia 18148, Ugo 9074 e Zeno 9074). */
function fer54BaseMista($test): array
{
    return fer54Base($test, [['Ugo', 'proprietario', 50], ['Ugo', 'nuda_proprietario', 50], ['Zeno', 'usufruttuario', 50]]);
}

/** La successione di Ugo, nudo proprietario della metà B, ai suoi eredi (nome → quota), con l'arretrato agli eredi. */
function fer54SuccessioneDellaNuda(array $s, string $il, array $eredi): array
{
    return ['tipo' => 'successione', 'tipologia' => 'nuda_proprietario', 'riga_uscente_id' => fds50Riga($s, 'Ugo', 'nuda_proprietario'), 'decorrenza' => $il, 'quota' => 50,
        'eredi' => array_map(fn (string $nome, float|int $quota) => ['anagrafica_id' => $s['persone'][$nome]->id, 'quota' => $quota], array_keys($eredi), array_values($eredi)),
        'arretrato' => Subentro::ARRETRATO_AGLI_EREDI, 'erede_di_riferimento' => $s['persone'][array_key_first($eredi)]->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 77', 'pertinenze' => []];
}

/**
 * Ugo vende a `$compra` la metà piena (1/10), muore il 15/10 (la nuda ai suoi eredi, con l'arretrato agli eredi) e `$compra` rivende la metà piena a Carlo il
 * 1/12. Con `$legate` le righe del Wallet della rivendita: `$compra` a credito e Carlo a debito di € 15,29.
 */
function fer54MistaFinoAllaRivendita($test, string $compra, array $eredi, bool $legate = false): array
{
    $s = fer54BaseMista($test);
    $s['p1'] = fer54Passo($test, $s, fds50ModuloVendita($s, 'Ugo', $compra, '2026-10-01', 50))['subentro'];
    $s['p2'] = fer54Passo($test, $s, fer54SuccessioneDellaNuda($s, '2026-10-15', $eredi))['subentro'];
    $s['pannello'] = fds50Anteprima($test, $s, fds50ModuloVendita($s, $compra, 'Carlo', '2026-12-01', 50));
    $s['p3'] = fer54Passo($test, $s, fds50ModuloVendita($s, $compra, 'Carlo', '2026-12-01', 50))['subentro'];
    if ($legate) {
        foreach ([[$compra, -1529], ['Carlo', 1529]] as [$chi, $cents]) {
            expect(sl53Errori(sl53Posta($test, $s, $chi, $cents, (int) $s['p3']->id)))->toBe([]);
        }
        $test->travel(1)->minutes();
    }

    return $s;
}

/** Le frasi del pannello, del motivo e dello storico della rivendita che dicono la cifra: nessuna deve nominare chi non c'entra. */
function fer54FrasiDellaCifra(array $s, string $cifra): array
{
    return [...fds50Con($s['pannello'], $cifra), ...fds50Con(fds50Storico($s, $s['p3']), $cifra)];
}

/** Nessuna frase del pannello ha un punto interrogativo al posto di un nome: «rimasta a carico di ?» è il segnaposto di una persona che il registro non sa. */
function fer54SenzaNomiMancanti(array $anteprima): void
{
    foreach (fds50Testi($anteprima) as $testo) {
        expect(preg_match('/(^|[\s(])\?([\s.,;)]|$)/u', $testo))->toBe(0, 'Un nome manca nella frase: ' . $testo);
    }
}

it('Coda 341 (fermata di prima a carico del defunto) — Ugo vende la metà piena a Bruno, muore (la nuda a Bruno, arretrato agli eredi) e Bruno la rivende a Carlo: € 15,29 a carico di Bruno, che ha preso il posto di Ugo, con il Wallet; Zeno non c\'entra', function () {
    $s = fer54MistaFinoAllaRivendita($this, 'Bruno', ['Bruno' => 50]);

    // Conto a mano: la vendita a Bruno (1/10) ferma 4537 a carico di Ugo (la sua riga di 9074 scende a 4537); la rivendita a Carlo (1/12) vale 18148 × 50 × 31/18400 =
    // 1528,77 → 1529. La riga di Ugo è passata a Bruno con la successione, e con lei la fermata: la cifra è la fermata dell'erede, a suo carico, con il Wallet.
    expect(fer54Registro($s['p1']))->toBe([['persone' => ['Ugo'], 'cifra' => 4537, 'riceve' => ['Bruno' => 4537], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    expect(fer54Registro($s['p3']))->toBe([['persone' => ['Bruno'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    $f = fds50Fermate($s['p3']->fresh())[0];
    expect($f['ereditata']['da'] ?? null)->toBe('Ugo')
        ->and($f['ereditata']['coppia'] ?? null)->toBe(9074)
        ->and($f['ereditata']['passaggio'] ?? null)->toBe('la successione del 15 ottobre 2026')
        ->and($f['ereditata']['sua'] ?? null)->toBe(9074);

    expect(fer54Frase($s['pannello'], 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'Con la successione del 15 ottobre 2026 una parte, € 90,74, è passata a Bruno. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 15,29 restano a carico di Bruno. '
        . 'Se vanno a Carlo, registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: € 15,29 a credito di Bruno e € 15,29 a debito di Carlo. '
        . 'Un saldo legato non occupa il posto di un riporto, e si toglie se annulli il passaggio.');
    expect(fds50Testi($s['pannello']))->toContain('€ 15,29 dei giorni dopo l\'uscita di Ines restano a carico di Bruno (gestione Ordinaria 2026): il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Carlo con questo passaggio');
    $storico = (string) collect(fds50Testi(fds50Storico($s, $s['p3'])))->first(fn (string $t) => str_starts_with($t, 'Il conguaglio scritto alla fine locazione di Ines'));
    expect($storico)->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 15,29 dei giorni dal 1 dicembre 2026 sono rimasti '
        . 'a carico di Bruno sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Carlo.');

    // Zeno ha la sua riga della coppia, ma la fermata non è sua: nessuna frase che dice la cifra lo nomina.
    expect(fer54FrasiDellaCifra($s, '15,29'))->not->toBeEmpty();
    foreach (fer54FrasiDellaCifra($s, '15,29') as $testo) {
        expect($testo)->not->toContain('Zeno');
    }
    fer54SenzaNomiMancanti($s['pannello']);
});

it('Coda 341 (fermata di prima a carico del defunto, due eredi) — la nuda di Ugo va a Bruno ed Elsa, e Bruno rivende la metà piena a Carlo: € 15,29 a carico di Bruno ed Elsa, che hanno preso il posto di Ugo, o di Bruno se la fermata della vendita è regolata', function () {
    $s = fer54MistaFinoAllaRivendita($this, 'Bruno', ['Bruno' => 25, 'Elsa' => 25]);

    // Conto a mano: la riga di Ugo (9074) passa ai due eredi a metà, 4537 e 4537; la fermata della vendita del 1/10 (€ 45,37) era sua, e il programma non la divide fra
    // loro: la cifra di 1529 resta a carico di Bruno ed Elsa insieme, o di Bruno se quella fermata è già stata regolata. Zeno non c'entra.
    expect(fer54Registro($s['p3']))->toBe([[
        'persone' => ['Bruno', 'Elsa'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => 'Bruno',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 4537], 'wallet' => false, 'strada' => null,
    ]]);
    expect(fer54Frase($s['pannello'], 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Bruno e Elsa hanno preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La vendita del 1 ottobre 2026 non ha spostato la parte di Bruno, € 45,37, rimasta a carico di Bruno e Elsa da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 15,29 restano a carico di Bruno e Elsa, '
        . 'o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 1 ottobre 2026, e il programma non lo sa.');
    expect(fds50Testi($s['pannello']))->toContain('€ 15,29 dei giorni dopo l\'uscita di Ines restano a carico di Bruno e Elsa, o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata (gestione Ordinaria 2026): '
        . 'il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Carlo con questo passaggio, come non era passato a Bruno con la vendita del 1 ottobre 2026');
    foreach (fer54FrasiDellaCifra($s, '15,29') as $testo) {
        expect($testo)->not->toContain('Zeno');
    }
    fer54SenzaNomiMancanti($s['pannello']);
});

it('Coda 341 (fermata di prima a carico di una persona viva, guardia) — Anna vende a Bruno, Ugo muore (la nuda a Bruno), Bruno rivende a Carlo la parte comprata da Anna: € 7,64 a carico di Anna, o di Bruno; la nuda ereditata non è nella catena di quella riga', function () {
    $s = fer54Base($this, [['Ugo', 'proprietario', 25], ['Anna', 'proprietario', 25], ['Ugo', 'nuda_proprietario', 50], ['Zeno', 'usufruttuario', 50]]);
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-10-01', 25));
    fer54Passo($this, $s, fer54SuccessioneDellaNuda($s, '2026-10-15', ['Bruno' => 50]));
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 25));
    $p3 = fer54Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 25))['subentro'];

    // Conto a mano: la coppia è Ugo 4537, Anna 4537, Zeno 9074; la vendita di Anna (1/10) ferma 4537 × 92/184 = 2268,5 → 2268 a carico di Anna (il centesimo a pari resto
    // resta a chi esce); la rivendita di Bruno vale 18148 × 25 × 31/18400 = 764,39 → 764. La parte che Bruno ha ereditato sta su un'altra riga (la nuda): per questa riga
    // Bruno non è a carico, e la fermata di prima è di Anna, che è viva.
    expect(fer54Registro($p3))->toBe([[
        'persone' => ['Anna'], 'cifra' => 764, 'riceve' => ['Carlo' => 764], 'uscente' => 'Bruno',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 2268], 'wallet' => false, 'strada' => null,
    ]]);
    expect(fer54Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Anna ha preso € 45,37 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La vendita del 1 ottobre 2026 non ha spostato la parte di Bruno, € 22,68, rimasta a carico di Anna da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 7,64 restano a carico di Anna, '
        . 'o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 1 ottobre 2026, e il programma non lo sa.');
    fer54SenzaNomiMancanti($pannello);
});

it('Coda 341 (righe legate dopo la fermata del defunto) — con le righe del Wallet legate alla rivendita di Bruno, l\'inizio di Luca dice che la parte che resta è di Bruno e di Carlo, e seguita la frase le righe sommano zero fra loro', function () {
    $s = fer54MistaFinoAllaRivendita($this, 'Bruno', ['Bruno' => 50], legate: true);
    $inizio = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));
    $frase = fer54Frase($inizio, 'Con questo inizio');

    // Conto a mano: Luca dal 15/12 toglie i suoi 17 giorni, e a Carlo restano i 14 giorni 1/12–14/12: 18148 × 50 × 14/18400 = 690,41 → € 6,90; degli € 15,29 della fermata
    // si restituiscono 1529 − 690 = € 8,39 a Carlo. Le righe legate (Bruno −1529, Carlo +1529) diventano −690 e +690: nessun saldo di Zeno, che non risponde della fermata.
    expect($frase)->toBe('La vendita del 1 dicembre 2026 ha lasciato a carico di Bruno € 15,29 dei giorni dopo l\'uscita di Ines, da regolare a mano con Carlo. '
        . 'Con questo inizio i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori: resta da regolare € 6,90 con Carlo. '
        . 'Se Bruno e Carlo hanno già regolato € 15,29, € 8,39 vanno restituiti a Carlo. '
        . 'I saldi del Wallet legati alla vendita del 1 dicembre 2026 sommano oggi € 15,29 a credito di Bruno e € 15,29 a debito di Carlo; '
        . 'la parte che resta è € 6,90 a credito di Bruno e € 6,90 a debito di Carlo. '
        . 'Il programma non li cambia: correggi dal Wallet i saldi che si modificano.');

    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['p3'], ['Bruno' => -690, 'Carlo' => 690], $frase))->toBe([]);
    expect(sl53Legate($s, $s['p3']))->toBe(['Bruno' => -690, 'Carlo' => 690]);
});

it('Coda 341 (fermata di prima a carico del defunto, chi compra non è erede) — Elsa compra da Ugo, Ugo muore (la nuda a Bruno e Dora) ed Elsa rivende a Carlo: € 15,29 a carico di Bruno e Dora, o di Elsa; Zeno non c\'entra', function () {
    $s = fer54MistaFinoAllaRivendita($this, 'Elsa', ['Bruno' => 25, 'Dora' => 25]);

    // Conto a mano: la riga di Ugo (9074) passa a Bruno e a Dora, 4537 e 4537; la fermata della vendita a Elsa (€ 45,37) era di Ugo, e ora è di Bruno e Dora insieme.
    // La rivendita di Elsa vale 1529. Elsa non è erede: la restrizione alle persone della fermata di prima legge Ugo come i suoi eredi.
    expect(fer54Registro($s['p3']))->toBe([[
        'persone' => ['Bruno', 'Dora'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => 'Elsa',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 4537], 'wallet' => false, 'strada' => null,
    ]]);
    expect(fer54Frase($s['pannello'], 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Bruno e Dora hanno preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La vendita del 1 ottobre 2026 non ha spostato la parte di Elsa, € 45,37, rimasta a carico di Bruno e Dora da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 15,29 restano a carico di Bruno e Dora, '
        . 'o di Elsa se la fermata della vendita del 1 ottobre 2026 è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 1 ottobre 2026, e il programma non lo sa.');
    foreach (fer54FrasiDellaCifra($s, '15,29') as $testo) {
        expect($testo)->not->toContain('Zeno');
    }
    fer54SenzaNomiMancanti($s['pannello']);
});

it('Coda 341 (fine di un conduttore nella catena) — Luca entra l\'1/8, Anna vende a Bruno, la locazione di Luca finisce il 5/10, Ugo muore (Bruno ed Elsa) e Bruno rivende a Carlo: € 19,11 a carico di Bruno, Elsa e Anna, o di Bruno; la fermata della fine di Luca era di Ugo e Anna', function () {
    $s = fer54Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer54Passo($this, $s, fds50ModuloInizio($s, 'Luca', '2026-08-01'));
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-10-01', 25));
    $fineDiLuca = fer54Passo($this, $s, b51aIntFine($s, '2026-10-05'))['subentro'];
    fer54Passo($this, $s, fer54Successione($s, '2026-10-15', 75));
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 62.5));
    $p3 = fer54Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 62.5))['subentro'];

    // Conto a mano: con Luca dall'1/8 i suoi giorni li rimborsa lui; alla fine della sua locazione, il 5/10, i giorni dal 5/10 al 31/12 (88) tornano a chi paga al posto
    // dell'inquilino, e a Bruno spettano 18148 × 25 × 88/18400 = 2169,87 → € 21,70, a carico di Ugo e Anna, che hanno la coppia (13611 e 4537). Ugo muore con
    // l'arretrato agli eredi: la sua riga passa a Bruno (6806) e a Elsa (6805), e con lei la fermata. La rivendita di Bruno vale 18148 × 62,5 × 31/18400 = 1910,96 → € 19,11,
    // a carico di chi aveva la fermata di Luca (gli eredi di Ugo e Anna) e di Bruno, che ha anche la parte avuta con la successione.
    expect(fer54Registro($fineDiLuca))->toBe([['persone' => ['Ugo', 'Anna'], 'cifra' => 2170, 'riceve' => ['Bruno' => 2170], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    expect(fer54Registro($p3))->toBe([[
        'persone' => ['Bruno', 'Elsa', 'Anna'], 'cifra' => 1911, 'riceve' => ['Carlo' => 1911], 'uscente' => 'Bruno',
        'dopo_la_fermata' => ['passaggio' => 'la fine della locazione di Luca del 5 ottobre 2026', 'cifra' => 2170], 'wallet' => false, 'strada' => null,
    ]]);
    expect(fer54Frase($pannello, 'Con questo passaggio'))->toContain('La fine della locazione di Luca del 5 ottobre 2026 non ha spostato la parte di Bruno, € 21,70, da regolare a mano. ')
        ->and(fer54Frase($pannello, 'Con questo passaggio'))->toContain('€ 19,11 restano a carico di Bruno, Elsa e Anna, o di Bruno se la fermata della fine della locazione di Luca del 5 ottobre 2026 è già stata regolata.');
    fer54SenzaNomiMancanti($pannello);
});

it('Coda 341 (arretrato al defunto, guardia) — Anna vende a Bruno, Ugo muore con l\'arretrato al defunto e «Scrivi» (la sua riga resta a lui), Bruno rivende tutto: € 19,11 a carico di Ugo e Anna, o di Bruno; Ugo non si legge come i suoi eredi', function () {
    $s = fer54Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-10-01', 25));
    fer54Passo($this, $s, array_replace(fer54Successione($s, '2026-10-15', 75), ['arretrato' => Subentro::ARRETRATO_AL_DEFUNTO, 'conguaglio' => Subentro::SCRIVI_IL_CONGUAGLIO]));
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 62.5));
    $p3 = fer54Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 62.5))['subentro'];

    // Conto a mano: con l'arretrato al defunto la riga della coppia di Ugo (13611) resta a lui; le fermate di prima sono due, la vendita di Anna (a carico di Anna) e la
    // successione con «Scrivi» (a carico di Ugo). La rivendita di Bruno vale 18148 × 62,5 × 31/18400 = 1910,96 → € 19,11, a carico di Ugo e Anna, o di Bruno.
    expect(fer54Registro($p3))->toBe([[
        'persone' => ['Ugo', 'Anna'], 'cifra' => 1911, 'riceve' => ['Carlo' => 1911], 'uscente' => 'Bruno', 'dopo_la_fermata' => null, 'wallet' => false, 'strada' => null,
    ]]);
    expect(fer54Frase($pannello, 'Con questo passaggio'))->toContain('€ 19,11 restano a carico di Ugo e Anna, o di Bruno se la parte di Bruno è già stata regolata.')
        ->and(fer54Frase($pannello, 'Con questo passaggio'))->not->toContain('Elsa');
    fer54SenzaNomiMancanti($pannello);
});

it('Coda 341 (successione nella catena, fermata di prima del defunto) — Ugo vende la metà piena a Bruno, l\'usufrutto di Zeno si estingue, Ugo muore (la sua metà piena a Bruno) e Bruno rivende tutto a Carlo: € 30,58 a carico di Bruno con il Wallet, e nessun nome manca', function () {
    $s = fer54BaseMista($this);
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Bruno', '2026-10-01', 50));
    fer54Passo($this, $s, ['quota' => 50] + fds50ModuloEstinzione($s, 'Zeno', '2026-10-05'));
    fer54Passo($this, $s, ['tipo' => 'successione', 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, 'Ugo', 'proprietario'), 'decorrenza' => '2026-10-15', 'quota' => 50,
        'eredi' => [['anagrafica_id' => $s['persone']['Bruno']->id, 'quota' => 50]], 'arretrato' => Subentro::ARRETRATO_AGLI_EREDI, 'erede_di_riferimento' => $s['persone']['Bruno']->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 77', 'pertinenze' => []]);
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100));
    $p3 = fer54Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100))['subentro'];

    // Conto a mano: Bruno ha 50 dall'1/10, 100 dal 15/10 (la sua metà più quella di Ugo) fino al 30/11; Zeno è usufruttuario fino al 4/10; Ugo ha 50 fino al 30/9 e 50 dal 5/10
    // al 14/10. Carlo con il 100 % per 31 giorni: 18148 × 3100/18400 = 3057,54 → 3058 (resti maggiori). La fermata di prima (la vendita a Bruno, € 45,37) era di Ugo,
    // e Bruno è il suo solo erede: la cifra è la fermata dell'erede, a suo carico, con il Wallet.
    // Una parte della cifra (la metà B, € 15,29) sta nella riga della coppia di Zeno, e l'estinzione dell'usufrutto l'aveva fermata a carico di Zeno verso Ugo: la frase la
    // mette a carico di Bruno per intero. È la Coda 346, che si risolve nella 1.11.0-beta.55: qui si fissa che cosa dice oggi la frase (niente nome mancante, niente
    // alternativa a se stesso).
    expect(fer54Registro($p3))->toBe([['persone' => ['Bruno'], 'cifra' => 3058, 'riceve' => ['Carlo' => 3058], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    expect(fer54Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'Con la successione del 15 ottobre 2026 una parte, € 90,74, è passata a Bruno. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 30,58 restano a carico di Bruno. '
        . 'Se vanno a Carlo, registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: € 30,58 a credito di Bruno e € 30,58 a debito di Carlo. '
        . 'Un saldo legato non occupa il posto di un riporto, e si toglie se annulli il passaggio.');
    fer54SenzaNomiMancanti($pannello);
});

/** La fermata dell'erede che ha anche comprato, come la lascia `dopoUnaFermata` (Bruno e Anna a carico, Bruno esce): il registro a mano, per provare le forme della frase. */
function fer54Fermata(?array $dopoLaFermata): array
{
    return ['inquilino' => 'Ines', 'fine_il' => '2026-07-01', 'persona' => 'Bruno e Anna', 'persone' => ['Bruno', 'Anna'], 'piu_persone' => true, 'coppia' => 11343,
        'gestione' => 'Ordinaria 2026', 'unita' => 'Interno 1', 'dal' => '2026-12-01', 'verso' => ['Carlo'], 'cifra' => 1911, 'uscente_id' => 6, 'uscente' => 'Bruno',
        'dopo_la_fermata' => $dopoLaFermata, 'ereditata' => ['da' => 'Ugo', 'coppia' => 13611, 'passaggio' => 'la successione del 15 ottobre 2026', 'sua' => 6806], 'wallet' => false];
}

it('la frase dell\'erede quando la fermata di prima era un credito: lo dice con le parole, senza «€ -»', function () {
    // Un'entrata che nei giorni di Bruno superava la spesa: la parte di Bruno nella vendita del 1/10 era un credito di € 2,27, rimasto ad Anna.
    $frase = FermataDopoScrivi::frasePannello(fer54Fermata(['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => -227, 'persona' => 'Anna']));

    expect($frase)->toContain('La vendita del 1 ottobre 2026 non ha spostato la parte di Bruno, un credito di € 2,27, rimasto ad Anna da regolare a mano.')
        ->and($frase)->not->toContain('€ -');
});

it('la frase dell\'erede quando la fermata di prima era a carico di più persone che il registro non nomina: dice la cifra e che resta da regolare a mano, senza inventare di chi', function () {
    $frase = FermataDopoScrivi::frasePannello(fer54Fermata(['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 2268]));

    expect($frase)->toContain('La vendita del 1 ottobre 2026 non ha spostato la parte di Bruno, € 22,68, da regolare a mano.')
        ->and($frase)->not->toContain('rimasta a carico di');
});

it('la frase dell\'erede quando le fermate di prima sono più d\'una: nessuna fermata è quella di prima, e l\'alternativa è la parte di chi esce', function () {
    $frase = FermataDopoScrivi::frasePannello(fer54Fermata(null));

    expect($frase)->toContain('€ 19,11 restano a carico di Bruno e Anna, o di Bruno se la parte di Bruno è già stata regolata.')
        ->and($frase)->toContain('chi la restituisce dipende da come è stata regolata la parte di Bruno, e il programma non lo sa.')
        ->and($frase)->not->toContain('non ha spostato la parte');
});

it('la frase dell\'erede con più acquisti dice ogni fermata di prima con la sua cifra, e di chi è rimasta quando il registro lo dice; un credito si dice con le parole', function () {
    $frase = FermataDopoScrivi::frasePannello(['dopo_le_fermate' => [
        ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 2268, 'persona' => 'Anna'],
        ['passaggio' => 'la vendita del 5 ottobre 2026', 'cifra' => -227],
    ]] + fer54Fermata(null));

    expect($frase)->toContain('La vendita del 1 ottobre 2026 non ha spostato la parte di Bruno, € 22,68, rimasta a carico di Anna da regolare a mano. '
        . 'La vendita del 5 ottobre 2026 non ha spostato la parte di Bruno, un credito di € 2,27, rimasto da regolare a mano.')
        ->and($frase)->not->toContain('€ -');
});

it('il motivo del cancello dell\'erede: con una fermata di prima nomina il passaggio, con più acquisti dice che sono le parti comprate a non essergli passate', function () {
    // Una fermata di prima sola: come non era passato a Bruno con quella vendita.
    expect(FermataDopoScrivi::motivoCancello(fer54Fermata(FER54_DOPO_LA_VENDITA_DI_ANNA)))
        ->toEndWith('non passa a Carlo con questo passaggio, come non era passato a Bruno con la vendita del 1 ottobre 2026');
    // Più acquisti: la parte avuta con la successione gli è passata, non si può dire che nulla gli era passato.
    expect(FermataDopoScrivi::motivoCancello(fer54Fermata(null)))
        ->toEndWith('non passa a Carlo con questo passaggio, come non erano passate a Bruno le parti che aveva comprato');
});

it('Coda 341 (acquisto dopo l\'eredità, unità mista) — Ugo vende la metà piena a Elsa, muore (la nuda a Bruno, arretrato agli eredi), Elsa vende la metà piena a Bruno e Bruno la rivende a Carlo: € 15,29 a carico di Bruno, con il Wallet; Zeno non c\'entra', function () {
    $s = fer54BaseMista($this);
    $s['p1'] = fer54Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 50))['subentro'];
    $s['p2'] = fer54Passo($this, $s, fer54SuccessioneDellaNuda($s, '2026-10-15', ['Bruno' => 50]))['subentro'];
    $s['p3'] = fer54Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 50))['subentro'];
    $s['pannello'] = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 50));
    $s['p4'] = fer54Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 50))['subentro'];

    // Conto a mano: la rivendita vale 18148 × 50 × 31/18400 = 1528,77 → 1529. Bruno ha la parte della coppia di Ugo come erede, e la riga che vende l'ha comprata
    // da Elsa, che la coppia non l'aveva: la vendita del 1/11 non ha fermata (chi ha la coppia non riceve), quindi nella catena della riga non c'è una fermata di
    // prima, e la fermata è dell'erede, a suo carico, con il Wallet.
    expect(fer54Registro($s['p4']))->toBe([['persone' => ['Bruno'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    expect(fer54FrasiDellaCifra(['pannello' => $s['pannello'], 'p3' => $s['p4']] + $s, '15,29'))->not->toBeEmpty();
    foreach (fer54FrasiDellaCifra(['pannello' => $s['pannello'], 'p3' => $s['p4']] + $s, '15,29') as $testo) {
        expect($testo)->not->toContain('Zeno');
    }
    fer54SenzaNomiMancanti($s['pannello']);
});

it('Coda 341 (l\'erede rivende, ricompra e rivende, unità mista) — Ugo vende la metà piena a Bruno e muore (la nuda a Bruno), Bruno la vende a Carlo, la ricompra e la vende a Dora: € 8,38 a carico di Bruno; Zeno non c\'entra', function () {
    $s = fer54BaseMista($this);
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Bruno', '2026-10-01', 50));
    fer54Passo($this, $s, fer54SuccessioneDellaNuda($s, '2026-10-15', ['Bruno' => 50]));
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-11-15', 50));
    fer54Passo($this, $s, fds50ModuloVendita($s, 'Carlo', 'Bruno', '2026-12-01', 50));
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Dora', '2026-12-15', 50));

    // Conto a mano: 18148 × 50 × 17/18400 = 838,36 → 838.
    $testi = fds50Con($pannello, '8,38');
    expect($testi)->not->toBeEmpty();
    foreach ($testi as $testo) {
        expect($testo)->not->toContain('Zeno');
    }
    expect(implode(' ', $testi))->toContain('restano a carico di Bruno');
    fer54SenzaNomiMancanti($pannello);
});
