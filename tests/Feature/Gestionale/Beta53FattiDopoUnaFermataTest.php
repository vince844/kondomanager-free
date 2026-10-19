<?php

/**
 * 1.11.0-beta.53 (decisione 100, punto 2) — i fatti dei saldi del Wallet legati a un passaggio quando la fermata è quella di chi era entrato con un
 * passaggio già fermato (decisione 81.2).
 *
 * Ugo vende a Elsa il 1/11 (fermata € 60,16 a carico di Ugo), poi Elsa vende a Carlo il 1/12 e anche questa vendita si ferma (€ 30,57). Il pannello
 * della seconda vendita dice che la parte resta a carico di Ugo, o di Elsa se la fermata della prima è già stata regolata, e che il programma non sa
 * quale delle due. Le righe legate alla seconda vendita sono di Elsa (a credito) e di Carlo (a debito).
 *
 * Le frasi che dicono la parte a carico (la fermata ridotta da un inizio, la parte che risale, l'annullamento dell'uno e dell'altra) non la danno a
 * Ugo come un fatto: dicono «a Ugo, o a Elsa se la fermata della vendita del 1 novembre 2026 è già stata regolata». Seguite dalle rotte in tutti e due
 * i modi, nessuna rotta rifiuta e le righe sommano la parte di ognuno. «Annulla il conguaglio» non legge la parte da sola: la frase la tace e il passo
 * è al condizionale.
 *
 * Una voce del registro scritta prima (senza chi è uscito) dà la stessa frase: lo legge dal passaggio della fermata.
 *
 * Fonte dell'atteso: le cifre della fermata (€ 30,57; con l'inizio del 15/12 restano € 13,81; con la fine del 20/12 salgono a € 25,64) e il conto a
 * mano scritto in ogni test.
 *
 * Che cosa NON copre: MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Services\Subentro\FermataDopoScrivi;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

/** L'alternativa della fermata: la parte a carico è di Ugo, o di Elsa se la fermata della prima vendita è già stata regolata. */
const SL53P_ALTERNATIVA = 'o di Elsa se la fermata della vendita del 1 novembre 2026 è già stata regolata';
/** La strada, per chi ha una riga che si modifica e per chi non ne ha una. */
const SL53P_STRADA = 'correggi dal Wallet i saldi che si modificano, e per chi non ne ha uno registra la differenza con un saldo legato alla vendita del 1 dicembre 2026.';

/**
 * La vendita di Ugo a Elsa il 1/11 e di Elsa a Carlo il 1/12 (fermata € 30,57, decisione 81.2), con le righe legate alla seconda: Elsa a credito
 * di € 30,57, Carlo a debito. `$s['v2']` è la seconda vendita.
 */
function sl53pSecondaVendita($test): array
{
    $s = sl53Scenario($test);
    fclPersona($s, 'Carlo');
    $s['v2'] = fds50Registra($test, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-12-01'))['subentro'];
    $s['cifra2'] = (int) (fds50Fermate($s['v2']->fresh())[0]['cifra'] ?? 0);
    expect($s['cifra2'])->toBe(3057);
    $test->travel(1)->minutes();
    foreach ([['Elsa', -3057], ['Carlo', 3057]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($test, $s, $chi, $cents, (int) $s['v2']->id)))->toBe([]);
    }
    $test->travel(1)->minutes();

    return $s;
}

/** La frase di una voce del pannello che nomina il Wallet, con le parole date. */
function sl53pFrase(array $anteprima, string $con): string
{
    return (string) collect(fds50Testi($anteprima))->first(fn (string $t) => str_contains($t, $con) && str_contains($t, 'Wallet'));
}

/** L'avviso dell'annullamento del passaggio che contiene le parole date, dallo storico. */
function sl53pAvviso(array $s, Subentro $passaggio, string $con): string
{
    return (string) collect(fds50Storico($s, $passaggio->fresh())['annullabile']['avvisi'] ?? [])->first(fn (string $t) => str_contains($t, $con));
}

/** Le righe della seconda vendita portate a mano alla parte di chi ha regolato la prima: Elsa a credito della parte, Carlo a debito. */
function sl53pPortaAllaParte($test, array $s, int $parte): void
{
    foreach (['Elsa' => -$parte, 'Carlo' => $parte] as $chi => $cents) {
        $riga = Saldo::where('passaggio_id', $s['v2']->id)->where('anagrafica_id', $s['persone'][$chi]->id)->sole();
        expect(sl53Errori($test->actingAs($test->user)->from('/saldi')->put(route('admin.gestionale.saldi.update', [$s['c'], $riga]), ['saldo_iniziale' => $cents, 'gestione_id' => $s['g']->id])))->toBe([]);
    }
}

it('l\'inizio di Luca che riduce la fermata 81.2: la frase dice la parte a carico di Ugo, o di Elsa se la prima fermata è già regolata; seguita nei due modi, nessuna rotta rifiuta e le righe sommano la parte', function (string $modo) {
    $s = sl53pSecondaVendita($this);
    $anteprima = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));
    $frase = sl53pFrase($anteprima, 'Con questo inizio');

    expect($frase)->toBe('La vendita del 1 dicembre 2026 ha lasciato a carico di Ugo, ' . SL53P_ALTERNATIVA . ', € 30,57 dei giorni dopo l\'uscita di Ines, da regolare a mano con Carlo. '
        . 'Con questo inizio i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori: resta da regolare € 13,81 con Carlo. '
        . 'Se chi ne risponde e Carlo hanno già regolato € 30,57, € 16,76 vanno restituiti a Carlo. '
        . 'I saldi del Wallet legati alla vendita del 1 dicembre 2026 sommano oggi € 30,57 a credito di Elsa e € 30,57 a debito di Carlo, e Ugo non ne ha; '
        . 'la parte che resta è € 13,81 a debito di Carlo e € 13,81 a credito di Ugo, ' . SL53P_ALTERNATIVA . '. Il programma non li cambia: ' . SL53P_STRADA);

    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));
    $this->travel(1)->minutes();
    // Conto a mano: la parte che resta è € 13,81. Se la prima fermata è regolata, la parte è di Elsa (la sua riga da −3057 a −1381); se no, è di
    // Ugo (−1381 registrato dalla porta) e la riga di Elsa va a zero.
    $parte = $modo === 'regolata' ? ['Elsa' => -1381, 'Carlo' => 1381] : ['Elsa' => 0, 'Ugo' => -1381, 'Carlo' => 1381];
    expect(sl53SeguiIFatti($this, $s, $s['v2'], $parte, $frase))->toBe([]);
    expect(sl53Legate($s, $s['v2']))->toBe($modo === 'regolata' ? ['Carlo' => 1381, 'Elsa' => -1381] : ['Carlo' => 1381, 'Elsa' => 0, 'Ugo' => -1381]);
})->with(['regolata', 'non regolata']);

it('la fine di Luca che fa risalire la parte 81.2: la frase dice la parte nuova a carico di Ugo, o di Elsa; seguita, le righe sommano la parte; l\'annullamento della fine dice la parte che torna con la stessa alternativa, e seguito le righe tornano a € 13,81', function () {
    $s = sl53pSecondaVendita($this);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));
    $this->travel(1)->minutes();
    sl53pPortaAllaParte($this, $s, 1381);
    $this->travel(1)->minutes();

    $anteprima = fds50Anteprima($this, $s, b51aIntFine($s, '2026-12-20'));
    $frase = sl53pFrase($anteprima, 'sale da');
    expect($frase)->toEndWith(' I saldi del Wallet legati alla vendita del 1 dicembre 2026 sommano oggi € 13,81 a credito di Elsa e € 13,81 a debito di Carlo, e Ugo non ne ha; '
        . 'la parte nuova è € 25,64 a debito di Carlo e € 25,64 a credito di Ugo, ' . SL53P_ALTERNATIVA . '. Il programma non li cambia: ' . SL53P_STRADA);

    $fine = fds50Registra($this, $s, b51aIntFine($s, '2026-12-20'))['subentro'];
    $this->travel(1)->minutes();
    // Conto a mano: la parte nuova è € 25,64; la riga di Elsa da −1381 a −2564, quella di Carlo da 1381 a 2564.
    expect(sl53SeguiIFatti($this, $s, $s['v2'], ['Elsa' => -2564, 'Carlo' => 2564], $frase))->toBe([]);
    expect(sl53Legate($s, $s['v2']))->toBe(['Carlo' => 2564, 'Elsa' => -2564]);
    $this->travel(1)->minutes();

    $avviso = sl53pAvviso($s, $fine, 'è risalita');
    expect($avviso)->toEndWith(' I saldi del Wallet legati alla vendita del 1 dicembre 2026 sommano oggi € 25,64 a credito di Elsa e € 25,64 a debito di Carlo, e Ugo non ne ha; '
        . 'la parte che torna è € 13,81 a debito di Carlo e € 13,81 a credito di Ugo, ' . SL53P_ALTERNATIVA . '. Annullare il passaggio non li cambia: ' . SL53P_STRADA);
    expect(sl53Errori(sl53Annulla($this, $s, $fine, 'Registrata per sbaglio, la locazione continua')))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $s['v2'], ['Elsa' => -1381, 'Carlo' => 1381], $avviso))->toBe([]);
    expect(sl53Legate($s, $s['v2']))->toBe(['Carlo' => 1381, 'Elsa' => -1381]);
});

it('l\'annullamento dell\'inizio di Luca sulla fermata 81.2: la parte che torna è a carico di Ugo, o di Elsa se la prima fermata è già regolata; seguito, le righe tornano a € 30,57', function () {
    $s = sl53pSecondaVendita($this);
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'))['subentro'];
    $this->travel(1)->minutes();
    sl53pPortaAllaParte($this, $s, 1381);
    $this->travel(1)->minutes();

    $avviso = sl53pAvviso($s, $inizio, 'è scesa');
    expect($avviso)->toStartWith('Con questo inizio la parte verso Carlo della fermata della vendita del 1 dicembre 2026 è scesa da € 30,57 a € 13,81: annullandolo torna da € 13,81 a € 30,57. Se le parti hanno già regolato la differenza fra loro, quell\'accordo va rivisto. ')
        ->and($avviso)->toEndWith(' I saldi del Wallet legati alla vendita del 1 dicembre 2026 sommano oggi € 13,81 a credito di Elsa e € 13,81 a debito di Carlo, e Ugo non ne ha; '
            . 'la parte che torna è € 30,57 a debito di Carlo e € 30,57 a credito di Ugo, ' . SL53P_ALTERNATIVA . '. Annullare il passaggio non li cambia: ' . SL53P_STRADA);
    expect(sl53Errori(sl53Annulla($this, $s, $inizio, 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $s['v2'], ['Elsa' => -3057, 'Carlo' => 3057], $avviso))->toBe([]);
    expect(sl53Legate($s, $s['v2']))->toBe(['Carlo' => 3057, 'Elsa' => -3057]);
});

it('«Annulla il conguaglio» della seconda vendita 81.2: la parte della fermata non si legge da sola, la frase la tace e il passo è al condizionale; seguito, le righe si correggono senza rifiuti', function () {
    $s = sl53pSecondaVendita($this);

    $atteso = 'Annullato il conguaglio, un saldo del Wallet legato a questo passaggio che lo correggeva conta da solo. I saldi del Wallet legati a questo passaggio sulla gestione Ordinaria 2026 sommano oggi € 30,57 a credito di Elsa e € 30,57 a debito di Carlo. '
        . 'L\'annullamento del conguaglio non li cambia: se non sono la parte della fermata di questo passaggio, correggi dal Wallet i saldi che si modificano.';
    expect(fds50Storico($s, $s['v2']->fresh())['conguaglio']['avvisi'] ?? null)->toBe([$atteso]);

    $r = sl53AnnullaConguaglio($this, $s, $s['v2']);
    expect(sl53Errori($r))->toBe([])
        ->and(sl53Messaggio($r)['message'])->toEndWith(' ' . $atteso);
    // Seguito: le righe vanno a zero dal Wallet, senza rifiuti.
    expect(sl53SeguiIFatti($this, $s, $s['v2'], ['Elsa' => 0, 'Carlo' => 0], $atteso))->toBe([]);
    expect(sl53Legate($s, $s['v2']))->toBe(['Carlo' => 0, 'Elsa' => 0]);
});

it('una voce del registro scritta prima, senza chi è uscito: l\'avviso dell\'annullamento dell\'inizio e quello della fine dicono la stessa alternativa, letta dal passaggio della fermata', function () {
    $s = sl53pSecondaVendita($this);
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'))['subentro'];
    $this->travel(1)->minutes();
    $fine = fds50Registra($this, $s, b51aIntFine($s, '2026-12-20'))['subentro'];
    $this->travel(1)->minutes();
    $avvisi = fn () => [
        'inizio' => FermataDopoScrivi::avvisoRidottaAnnullando($inizio->fresh()->registro['fermata_ridotta'][0]),
        'fine' => FermataDopoScrivi::avvisoRisaleAnnullando($fine->fresh()->registro['fermata_risale'][0]),
    ];
    $nuovi = $avvisi();
    expect($nuovi['inizio'])->toContain(SL53P_ALTERNATIVA)
        ->and($nuovi['fine'])->toContain(SL53P_ALTERNATIVA);

    // Il registro come lo scrivevano la .51 e la .52: la voce non porta chi è uscito.
    foreach ([[$inizio, 'fermata_ridotta'], [$fine, 'fermata_risale']] as [$passaggio, $chiave]) {
        $reg = $passaggio->fresh()->registro;
        foreach (array_keys($reg[$chiave]) as $k) {
            unset($reg[$chiave][$k]['uscente_id'], $reg[$chiave][$k]['uscente'], $reg[$chiave][$k]['dopo_la_fermata']);
        }
        $passaggio->forceFill(['registro' => $reg])->save();
        expect($passaggio->fresh()->registro[$chiave][0])->not->toHaveKey('uscente_id');
    }
    expect($avvisi())->toBe($nuovi);
});

/**
 * Un gruppo di fermate sulla stessa persona che riceve, una normale e una 81.2. Ugo e Anna sono al 50 % della coppia di Ines (fine locazione del
 * 1 luglio con «Scrivi»). Anna vende a Elsa il 1/10; poi Ugo e Elsa vendono a Carlo, nell'ordine dato: la vendita di Ugo ha una fermata normale
 * (€ 30,08, a carico di Ugo), quella di Elsa una fermata 81.2 (€ 15,29, a carico di Anna o di Elsa se la fermata della vendita di Anna è già regolata).
 * Le righe legate: Ugo a credito di € 30,08 e Carlo a debito sulla vendita di Ugo; sulla vendita di Elsa Carlo a debito di € 15,29 e a credito
 * Elsa (modo «regolata») o Anna (modo «non regolata»). `$s['vu']` è la vendita di Ugo, `$s['ve']` quella di Elsa.
 */
function sl53pGruppoMisto($test, string $modo, string $prima = 'Ugo'): array
{
    $s = fds50Scenario([['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fclPersona($s, 'Carlo');
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($test, $s, '2026-09-30');
    $test->travel(1)->minutes();
    fds50Registra($test, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 50));
    $test->travel(1)->minutes();
    $date = $prima === 'Ugo' ? ['vu' => '2026-11-01', 've' => '2026-12-01'] : ['ve' => '2026-11-01', 'vu' => '2026-12-01'];
    foreach ($date as $chi => $il) {
        $s[$chi] = fds50Registra($test, $s, $chi === 'vu' ? fds50ModuloVendita($s, 'Ugo', 'Carlo', $il, 50) : fds50ModuloVendita($s, 'Elsa', 'Carlo', $il, 50))['subentro'];
        $test->travel(1)->minutes();
    }
    $s['cifra_vu'] = (int) (fds50Fermate($s['vu']->fresh())[0]['cifra'] ?? 0);
    $s['cifra_ve'] = (int) (fds50Fermate($s['ve']->fresh())[0]['cifra'] ?? 0);
    $s['credito'] = $modo === 'regolata' ? 'Elsa' : 'Anna';
    foreach ([[$s['ve'], $s['credito'], -$s['cifra_ve']], [$s['ve'], 'Carlo', $s['cifra_ve']], [$s['vu'], 'Ugo', -$s['cifra_vu']], [$s['vu'], 'Carlo', $s['cifra_vu']]] as [$p, $chi, $cents]) {
        expect(sl53Errori(sl53Posta($test, $s, $chi, $cents, (int) $p->id)))->toBe([]);
    }
    $test->travel(1)->minutes();

    return $s;
}

it('un gruppo di fermate sulla stessa persona, una normale (Ugo a Carlo) e una 81.2 (Elsa a Carlo): la frase dell\'inizio non mette l\'alternativa su tutto il carico, tace la parte e dice il passo al condizionale; seguita nei due modi, nessuna rotta rifiuta e la parte di Ugo resta a Ugo', function (string $modo) {
    $s = sl53pGruppoMisto($this, $modo);
    expect([$s['cifra_vu'], $s['cifra_ve']])->toBe([3008, 1529]);
    $frase = sl53pFrase(fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15')), 'Con questo inizio');

    // Conto a mano: i giorni di Luca dal 15/12 al 31/12 sono 17 dei 184 giorni dopo l'uscita di Ines, € 8,38 su ognuna delle due coppie di € 90,74
    // (quella di Ugo e quella di Anna), € 16,77 in tutto: dei € 45,37 lasciati restano € 28,60.
    $credito = $s['credito'];
    expect($frase)->toBe('La vendita del 1 novembre 2026 e la vendita del 1 dicembre 2026 hanno lasciato a carico di Ugo e Anna € 45,37 dei giorni dopo l\'uscita di Ines, da regolare a mano con Carlo. '
        . 'Con questo inizio i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori: resta da regolare € 28,60 con Carlo. '
        . 'Se Ugo, Anna e Carlo hanno già regolato € 45,37, € 16,77 vanno restituiti a Carlo. '
        . 'Il residuo è uno solo per Ugo e Anna insieme: la parte di ognuno la decidi tu, partendo da quella della sua fermata (€ 30,08 per Ugo e € 15,29 per Anna). '
        . "I saldi del Wallet legati alla vendita del 1 novembre 2026 e alla vendita del 1 dicembre 2026 sommano oggi € 15,29 a credito di {$credito}, € 45,37 a debito di Carlo e € 30,08 a credito di Ugo. "
        . 'Il programma non li cambia: se non sono la parte che resta, correggi dal Wallet i saldi che si modificano.');
    expect($frase)->not->toContain('già stata regolata');

    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));
    $this->travel(1)->minutes();
    // Seguito: la parte che resta è € 21,70 di Ugo e € 6,90 di chi ha la fermata di Elsa (€ 28,60 in tutto); ognuno corregge la sua riga dal Wallet.
    expect(sl53SeguiIFatti($this, $s, $s['vu'], ['Ugo' => -2170, 'Carlo' => 2170], $frase))->toBe([])
        ->and(sl53SeguiIFatti($this, $s, $s['ve'], [$credito => -690, 'Carlo' => 690], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vu']))->toEqual(['Carlo' => 2170, 'Ugo' => -2170])
        ->and(sl53Legate($s, $s['ve']))->toEqual(['Carlo' => 690, $credito => -690]);
})->with(['regolata', 'non regolata']);

it('il gruppo misto, nell\'uno e nell\'altro ordine di registrazione: la fine di Luca e i due annullamenti non dicono la parte né l\'alternativa di Elsa, e il passo è al condizionale', function (string $prima) {
    $s = sl53pGruppoMisto($this, 'regolata', $prima);
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'))['subentro'];
    $this->travel(1)->minutes();
    // La voce è una sola per le due vendite, col passaggio registrato per primo; non porta chi è uscito, perché non tutte le sue fermate lo dicono.
    $voce = $inizio->fresh()->registro['fermata_ridotta'][0];
    expect($voce['passaggio_id'])->toBe((int) $s[$prima === 'Ugo' ? 'vu' : 've']->id)
        ->and($voce['passaggi_ids'])->toBe([(int) $s[$prima === 'Ugo' ? 'vu' : 've']->id, (int) $s[$prima === 'Ugo' ? 've' : 'vu']->id])
        ->and($voce)->not->toHaveKey('uscente_id');
    $avvisoInizio = sl53pAvviso($s, $inizio, 'è scesa');
    $fine = fds50Anteprima($this, $s, b51aIntFine($s, '2026-12-20'));
    $sale = sl53pFrase($fine, 'sale da');
    expect($sale)->toContain('I saldi del Wallet legati alla vendita del')
        ->and($sale)->not->toContain('già stata regolata')
        ->and($sale)->not->toContain('la parte nuova è')
        ->and($sale)->toEndWith('. Il programma non li cambia: se non sono la parte nuova, correggi dal Wallet i saldi che si modificano.');

    $fineRegistrata = fds50Registra($this, $s, b51aIntFine($s, '2026-12-20'))['subentro'];
    $this->travel(1)->minutes();
    $avvisoFine = sl53pAvviso($s, $fineRegistrata, 'è risalita');
    foreach ([[$avvisoFine, 'la parte che torna', 'è risalita'], [$avvisoInizio, 'la parte che torna', 'è scesa']] as [$avviso, $quale, $verbo]) {
        expect($avviso)->toContain($verbo)
            ->and($avviso)->not->toContain('già stata regolata')
            ->and($avviso)->not->toContain($quale . ' è')
            ->and($avviso)->toEndWith('. Annullare il passaggio non li cambia: se non sono la parte che torna, correggi dal Wallet i saldi che si modificano.');
    }
    // La stessa frase dalle voci del registro, lette dal passaggio come le leggono le voci scritte prima.
    expect(FermataDopoScrivi::avvisoRisaleAnnullando($fineRegistrata->fresh()->registro['fermata_risale'][0]))->toBe($avvisoFine)
        ->and(FermataDopoScrivi::avvisoRidottaAnnullando($inizio->fresh()->registro['fermata_ridotta'][0]))->toBe($avvisoInizio);
})->with(['Ugo', 'Elsa']);

it('due fermate 81.2 della stessa persona uscita con la fermata di prima diversa (Elsa vende a Carlo la parte avuta da Anna e poi quella avuta da Ugo): l\'alternativa è la parte di Elsa, non la fermata di un passaggio solo, e la parte di ognuno la decide l\'amministratore', function () {
    $s = fds50Scenario([['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fclPersona($s, 'Carlo');
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($this, $s, '2026-09-30');
    $this->travel(1)->minutes();
    $vendite = [];
    foreach ([['Anna', 'Elsa', '2026-10-01'], ['Elsa', 'Carlo', '2026-11-01'], ['Ugo', 'Elsa', '2026-11-15'], ['Elsa', 'Carlo', '2026-12-01']] as [$vende, $compra, $il]) {
        $vendite[] = fds50Registra($this, $s, fds50ModuloVendita($s, $vende, $compra, $il, 50))['subentro'];
        $this->travel(1)->minutes();
    }
    [, $f1, , $f2] = $vendite;
    $c1 = (int) (fds50Fermate($f1->fresh())[0]['cifra'] ?? 0);
    $c2 = (int) (fds50Fermate($f2->fresh())[0]['cifra'] ?? 0);
    expect([$c1, $c2])->toBe([3008, 1529]);
    foreach ([[$f1, 'Elsa', -$c1], [$f1, 'Carlo', $c1], [$f2, 'Elsa', -$c2], [$f2, 'Carlo', $c2]] as [$p, $chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $p->id)))->toBe([]);
    }
    $this->travel(1)->minutes();

    // La prima fermata è a carico di Anna, o di Elsa se la vendita del 1 ottobre è regolata; la seconda a carico di Ugo, o di Elsa se la vendita del
    // 15 novembre è regolata: ognuna è di Elsa se la sua fermata è regolata, quindi l'alternativa è la parte di Elsa, non la fermata di un passaggio
    // solo, e il residuo è uno solo per Anna e Ugo (decisione 86.1). Conto a mano: € 45,37 − € 16,77 dei giorni di Luca = € 28,60.
    $frase = sl53pFrase(fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15')), 'Con questo inizio');
    expect($frase)->toBe('La vendita del 1 novembre 2026 e la vendita del 1 dicembre 2026 hanno lasciato a carico di Anna e Ugo, o di Elsa se la parte di Elsa è già stata regolata, '
        . '€ 45,37 dei giorni dopo l\'uscita di Ines, da regolare a mano con Carlo. Con questo inizio i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri '
        . 'accessori: resta da regolare € 28,60 con Carlo. Se chi ne risponde e Carlo hanno già regolato € 45,37, € 16,77 vanno restituiti a Carlo. Il residuo è uno solo per '
        . 'Anna e Ugo insieme: la parte di ognuno la decidi tu, partendo da quella della sua fermata (€ 30,08 per Anna e € 15,29 per Ugo). I saldi del Wallet legati alla '
        . 'vendita del 1 novembre 2026 e alla vendita del 1 dicembre 2026 sommano oggi € 45,37 a credito di Elsa e € 45,37 a debito di Carlo, e Anna e Ugo non ne hanno; '
        . 'la parte che resta è € 28,60 a debito di Carlo e € 28,60 a credito di Anna e Ugo insieme, o di Elsa se la parte di Elsa è già stata regolata. Il programma non '
        . 'li cambia: correggi dal Wallet i saldi che si modificano, e per chi non ne ha uno registra la differenza con un saldo legato a uno di quei passaggi.');

    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'))['subentro'];
    $this->travel(1)->minutes();
    $voce = $inizio->fresh()->registro['fermata_ridotta'][0];
    expect($voce['uscente'])->toBe('Elsa')
        ->and($voce['dopo_la_fermata'])->toBeNull()
        ->and($voce['persone'])->toBe(['Anna', 'Ugo']);
    $testi = implode(' | ', [sl53pAvviso($s, $inizio, 'è scesa'), sl53pFrase(fds50Anteprima($this, $s, b51aIntFine($s, '2026-12-20')), 'sale da'),
        ...fds50Testi(fds50Storico($s, $inizio))]);
    expect($testi)->not->toContain('1 ottobre 2026 è già stata regolata')
        ->and($testi)->not->toContain('15 novembre 2026 è già stata regolata')
        ->and($testi)->toContain('la parte che torna è € 45,37 a debito di Carlo e € 45,37 a credito di Anna e Ugo insieme, o di Elsa se la parte di Elsa è già stata regolata')
        ->and($testi)->toContain('La parte da regolare a mano fra chi ne risponde (Anna e Ugo, o Elsa se la parte di Elsa è già stata regolata) e Carlo è questa')
        ->and($testi)->toContain('(€ 45,37 rimasti a carico di Anna e Ugo, o di Elsa se la parte di Elsa è già stata regolata) restano da regolare fra chi ne risponde e Carlo € 28,60');
});

it('Elsa compra la parte di Anna e quella di Ugo e rivende tutto a Carlo: la fermata è a carico di Anna e Ugo insieme, o di Elsa se la sua parte è già stata regolata, non del solo Ugo', function () {
    $s = fds50Scenario([['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fclPersona($s, 'Carlo');
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($this, $s, '2026-09-30');
    $this->travel(1)->minutes();
    foreach ([['Anna', '2026-10-01'], ['Ugo', '2026-10-15']] as [$vende, $il]) {
        fds50Registra($this, $s, fds50ModuloVendita($s, $vende, 'Elsa', $il, 50));
        $this->travel(1)->minutes();
    }
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-12-01'));
    $v = fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-12-01'))['subentro'];
    $this->travel(1)->minutes();

    // Conto a mano: € 181,48 × 31/184 = € 30,58 dei giorni dal 1/12, metà dalla parte che Elsa ha avuto da Anna e metà da quella avuta da Ugo. Ognuna
    // è a carico di chi ha la sua riga della coppia, o di Elsa se la fermata di quella vendita è regolata: il programma non sa quale, e non la dà a Ugo.
    $f = fds50Fermate($v->fresh())[0];
    expect($f['cifra'])->toBe(3058)
        ->and($f['piu_persone'])->toBeTrue()
        ->and(in_array($f['persona'], ['Anna e Ugo', 'Ugo e Anna'], true))->toBeTrue()
        ->and($f['dopo_la_fermata'])->toBeNull()
        ->and($f['uscente'])->toBe('Elsa');
    $conLaCifra = implode(' | ', fds50Con($pannello, '30,58'));
    expect($conLaCifra)->toContain('La parte di Elsa non è passata con la coppia, ed è rimasta a carico di Ugo e Anna. Con questo passaggio la parte di quei giorni dal 1 '
        . 'dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 30,58 restano a carico di Ugo e Anna, o di Elsa se la parte di Elsa è già stata regolata.')
        ->and($conLaCifra)->toContain('€ 30,58 dei giorni dopo l\'uscita di Ines restano a carico di Ugo e Anna, o di Elsa se la parte di Elsa è già stata regolata (gestione Ordinaria 2026)')
        ->and($conLaCifra)->not->toContain('15 ottobre 2026 è già stata regolata');

    foreach ([['Elsa', -3058], ['Carlo', 3058]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $v->id)))->toBe([]);
    }
    $this->travel(1)->minutes();
    $frase = sl53pFrase(fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15')), 'Con questo inizio');
    // Conto a mano: dei € 30,58 Luca ne rimborsa € 16,77 (i giorni dal 15/12), ne restano € 13,81.
    expect($frase)->toBe('La vendita del 1 dicembre 2026 ha lasciato a carico di Ugo e Anna, o di Elsa se la parte di Elsa è già stata regolata, € 30,58 dei giorni dopo '
        . 'l\'uscita di Ines, da regolare a mano con Carlo. Con questo inizio i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori: resta da '
        . 'regolare € 13,81 con Carlo. Se chi ne risponde e Carlo hanno già regolato € 30,58, € 16,77 vanno restituiti a Carlo. I saldi del Wallet legati alla vendita del 1 '
        . 'dicembre 2026 sommano oggi € 30,58 a credito di Elsa e € 30,58 a debito di Carlo, e Ugo e Anna non ne hanno; la parte che resta è € 13,81 a debito di Carlo e '
        . '€ 13,81 a credito di Ugo e Anna insieme, o di Elsa se la parte di Elsa è già stata regolata. Il programma non li cambia: correggi dal Wallet i saldi che si '
        . 'modificano, e per chi non ne ha uno registra la differenza con un saldo legato alla vendita del 1 dicembre 2026.');
});

it('il motivo del cancello e lo storico dell\'inizio che riduce la fermata 81.2 dicono anche l\'alternativa di Elsa, come il pannello', function () {
    $s = sl53pSecondaVendita($this);
    $anteprima = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'))['subentro'];
    $this->travel(1)->minutes();

    $motivo = (string) collect(fds50Con($anteprima, 'non più'))->first(fn (string $t) => str_contains($t, '13,81'));
    $storico = (string) collect(fds50Testi(fds50Storico($s, $inizio)))->first(fn (string $t) => str_starts_with($t, 'Con questo inizio, della fermata'));
    expect($motivo)->toContain('restano da regolare fra chi ne risponde (Ugo, o Elsa se la fermata della vendita del 1 novembre 2026 è già stata regolata) e Carlo € 13,81')
        ->and($storico)->toContain('rimasti a carico di Ugo, ' . SL53P_ALTERNATIVA . ') restano da regolare fra chi ne risponde e Carlo € 13,81');
});

it('un gruppo con una fermata a carico di più persone insieme (Anna e Bruno) e una di Ugo: la frase dell\'inizio legge le righe di tutti e tre, e nessuno ha una parte a zero', function () {
    $s = fds50Scenario([['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 25], ['Bruno', 'proprietario', 25]]);
    fclPersona($s, 'Carlo');
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($this, $s, '2026-09-30');
    $this->travel(1)->minutes();
    $vendite = [];
    foreach ([['Anna', 'Elsa', '2026-10-01', 25], ['Bruno', 'Elsa', '2026-10-10', 25], ['Elsa', 'Carlo', '2026-10-20', 50], ['Ugo', 'Elsa', '2026-11-01', 50], ['Elsa', 'Carlo', '2026-11-15', 50]] as [$vende, $compra, $il, $quota]) {
        $vendite[] = fds50Registra($this, $s, fds50ModuloVendita($s, $vende, $compra, $il, $quota))['subentro'];
        $this->travel(1)->minutes();
    }
    [, , $f1, , $f2] = $vendite;
    $prima = fds50Fermate($f1->fresh())[0];
    expect($prima['persone'])->toBe(['Anna', 'Bruno'])
        ->and([$prima['cifra'], fds50Fermate($f2->fresh())[0]['cifra']])->toBe([3600, 2318]);
    foreach ([[$f1, 'Anna', -1800], [$f1, 'Bruno', -1800], [$f1, 'Carlo', 3600], [$f2, 'Ugo', -2318], [$f2, 'Carlo', 2318]] as [$p, $chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $p->id)))->toBe([]);
    }
    $this->travel(1)->minutes();

    $frase = sl53pFrase(fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15')), 'Con questo inizio');
    expect($frase)->not->toContain('€ 0,00 per')
        ->and($frase)->toContain('a carico di Anna, Bruno e Ugo, o di Elsa se la parte di Elsa è già stata regolata')
        ->and($frase)->toContain('Il residuo è uno solo per Anna, Bruno e Ugo insieme: la parte di ognuno la decidi tu, partendo da quella della sua fermata (€ 36,00 per Anna e Bruno e € 23,18 per Ugo).');
});

it('la fermata della fine di un conduttore a carico di più persone insieme porta tutte le persone: la frase dell\'inizio dopo legge le righe di Ugo e di Anna, e nessuno ha una parte a zero', function () {
    $s = fds50Scenario([['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fclPersona($s, 'Bruno');
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($this, $s, '2026-09-30');
    $this->travel(1)->minutes();
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-08-01'));
    $this->travel(1)->minutes();
    foreach (['Ugo', 'Anna'] as $vende) {
        fds50Registra($this, $s, fds50ModuloVendita($s, $vende, 'Elsa', '2026-10-01', 50));
        $this->travel(1)->minutes();
    }
    $fine = fds50Registra($this, $s, b51aIntFine($s, '2026-11-01'))['subentro'];
    $this->travel(1)->minutes();
    $f = fds50Fermate($fine->fresh())[0];
    expect($f['persone'])->toBe(['Ugo', 'Anna'])
        ->and($f['persone_ids'])->toBe([(int) $s['persone']['Ugo']->id, (int) $s['persone']['Anna']->id])
        ->and($f['cifra'])->toBe(6016);
    foreach ([['Ugo', -3008], ['Anna', -3008], ['Elsa', 6016]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $fine->id)))->toBe([]);
    }
    $this->travel(1)->minutes();

    $frase = sl53pFrase(fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Bruno', '2026-12-01')), 'Con questo inizio');
    expect($frase)->not->toContain('€ 0,00 per')
        ->and($frase)->toContain('a credito di Ugo e Anna insieme');
});

/**
 * Una coppia «Scrivi» sulla fine di Ines con i titolari dati, poi i passaggi dati in ordine (vendite e, per nome, l'inizio o la fine di Luca e la
 * successione di Ugo); l'ultimo è la rivendita di chi esce, e la sua fermata è quella che si guarda.
 *
 * @param list<array{0: string, 1: string, 2: string, 3?: int|float}> $passi [chi vende o «inizio», «fine», «successione»; chi compra; il giorno; la quota]
 */
function sl53pCatena($test, array $titolari, array $passi): array
{
    $s = fds50Scenario($titolari);
    foreach (['Carlo', 'Bruno'] as $nome) {
        isset($s['persone'][$nome]) || fclPersona($s, $nome);
    }
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($test, $s, '2026-09-30');
    $test->travel(1)->minutes();
    foreach ($passi as $p) {
        $dati = match ($p[0]) {
            'inizio' => fds50ModuloInizio($s, 'Luca', $p[2]),
            'fine' => b51aIntFine($s, $p[2]),
            // Gli eredi, Anna e Bruno, a metà della quota di Ugo.
            'successione' => ['quota' => $p[3], 'eredi' => [['anagrafica_id' => $s['persone']['Anna']->id, 'quota' => $p[3] / 2], ['anagrafica_id' => $s['persone']['Bruno']->id, 'quota' => $p[3] / 2]]]
                + fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, $p[2]),
            default => fds50ModuloVendita($s, $p[0], $p[1], $p[2], $p[3] ?? 100),
        };
        $s['ultimo'] = fds50Registra($test, $s, $dati)['subentro'];
        $test->travel(1)->minutes();
    }
    $s['fermata'] = fds50Fermate($s['ultimo']->fresh())[0] ?? [];

    return $s;
}

it('le parti di chi esce vengono dalla catena delle sue righe: quelle rivendute prima non contano, la successione e la fine di un conduttore sì', function (array $titolari, array $passi, array $persone, string $uscente) {
    $s = sl53pCatena($this, $titolari, $passi);
    $f = $s['fermata'];
    $nomi = array_map(fn (int $id) => \App\Models\Anagrafica::whereKey($id)->value('nome'), (array) ($f['persone_ids'] ?? [(int) ($f['persona_id'] ?? 0)]));
    sort($nomi);

    expect($nomi)->toBe($persone)
        ->and($f['uscente'] ?? null)->toBe($uscente)
        ->and(array_key_exists('dopo_la_fermata', $f) && $f['dopo_la_fermata'] === null)->toBeTrue();
})->with([
    // Elsa ha rivenduto a Carlo la parte avuta da Anna prima di comprare quelle di Ugo e di Bruno: la sua riga di oggi viene da quelle due vendite.
    'la parte di Anna già rivenduta' => [[['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 25], ['Bruno', 'proprietario', 25]],
        [['Anna', 'Elsa', '2026-10-01', 25], ['Elsa', 'Carlo', '2026-10-10', 25], ['Ugo', 'Elsa', '2026-10-20', 50], ['Bruno', 'Elsa', '2026-11-01', 25], ['Elsa', 'Carlo', '2026-12-01', 75]],
        ['Bruno', 'Ugo'], 'Elsa'],
    // Bruno ha comprato la parte di Anna e poi ha ereditato da Ugo: la sua riga viene dalla vendita e dalla successione.
    'una vendita e una successione' => [[['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]],
        [['Anna', 'Bruno', '2026-10-01', 25], ['successione', '', '2026-10-15', 75], ['Bruno', 'Carlo', '2026-12-01', 62.5]],
        ['Anna', 'Ugo'], 'Bruno'],
    // Elsa ha comprato da Anna, poi da Ugo mentre c'era Luca (senza fermata), e la fine di Luca ha lasciato la sua fermata: la riga viene da due vendite.
    'due vendite e la fine di un conduttore' => [[['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]],
        [['Anna', 'Elsa', '2026-10-01', 50], ['inizio', '', '2026-10-10'], ['Ugo', 'Elsa', '2026-11-01', 50], ['fine', '', '2026-11-20'], ['Elsa', 'Carlo', '2026-12-01', 100]],
        ['Anna', 'Ugo'], 'Elsa'],
]);

/** La coppia «Scrivi» sulla fine di Ines con i titolari dati, poi i passaggi dati in ordine; la fermata dell'ultimo. */
function sl53pPassi($test, array $titolari, array $passi): array
{
    $s = fds50Scenario($titolari);
    fclPersona($s, 'Carlo');
    fclPersona($s, 'Dora');
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($test, $s, '2026-09-30');
    $test->travel(1)->minutes();
    foreach ($passi as $dati) {
        $s['ultimo'] = fds50Registra($test, $s, $dati($s))['subentro'];
        $test->travel(1)->minutes();
    }

    return fds50Fermate($s['ultimo']->fresh());
}

it('le fermate di prima sono quelle dei passaggi della catena della riga di chi esce, non quelle di un\'altra sua riga; con una fermata sola l\'alternativa la nomina', function (array $titolari, array $passi, string $persona, string $prima) {
    $f = sl53pPassi($this, $titolari, $passi);

    expect($f)->toHaveCount(1)
        ->and($f[0]['persona'])->toBe($persona)
        ->and($f[0]['dopo_la_fermata']['passaggio'] ?? null)->toBe($prima);
})->with([
    // La riserva lascia Elsa nuda proprietaria senza fermata; l'estinzione la fa tornare piena, con la fermata a carico di Ugo: una sola, e la frase la nomina.
    'la riserva, poi l\'estinzione' => [[['Ugo', 'proprietario', 100]], [
        fn (array $s) => fds50ModuloRiserva($s, 'Ugo', 'Elsa', '2026-10-01'),
        fn (array $s) => fds50ModuloEstinzione($s, 'Ugo', '2026-10-15'),
        fn (array $s) => fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-12-01'),
    ], 'Ugo', 'l\'estinzione dell\'usufrutto del 15 ottobre 2026'],
    // L'usufrutto di Elsa viene dalla metà comprata da Ugo; la metà comprata da Anna è un'altra riga, e la sua fermata non c'entra.
    'l\'usufrutto e un\'altra riga della stessa persona' => [[['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]], [
        fn (array $s) => fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 50),
        fn (array $s) => ['quota' => 50] + fds50ModuloRiserva($s, 'Elsa', 'Carlo', '2026-10-10'),
        fn (array $s) => fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-20', 50),
        fn (array $s) => ['quota' => 50] + fds50ModuloEstinzione($s, 'Elsa', '2026-11-15'),
    ], 'Ugo', 'la vendita del 1 ottobre 2026'],
    // L'usufrutto costituito da Ugo è una riga sola, con la sua fermata; la metà comprata da Anna dopo è un'altra riga.
    'la costituzione e un\'altra riga della stessa persona' => [[['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]], [
        fn (array $s) => ['quota' => 50] + fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-10-01'),
        fn (array $s) => ['tipologia' => 'nuda_proprietario', 'riga_uscente_id' => fds50Riga($s, 'Ugo', 'nuda_proprietario')] + fds50ModuloVendita($s, 'Ugo', 'Dora', '2026-10-05', 50),
        fn (array $s) => fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-20', 50),
        fn (array $s) => ['quota' => 50] + fds50ModuloEstinzione($s, 'Elsa', '2026-11-15'),
    ], 'Ugo', 'la costituzione d\'usufrutto del 1 ottobre 2026'],
]);
