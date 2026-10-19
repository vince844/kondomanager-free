<?php

/**
 * 1.11.0-beta.53 (decisione 100, punto 2) — le voci del registro scritte dalla .51 e dalla .52 e le righe legate aggiunte dopo l'aggiornamento.
 *
 * Una fermata ridotta o risalita registrata prima della .53 non ha `parti_prima`, `parti_dopo` né `persone_ids`. Le righe legate alla sua fermata, scritte
 * dopo, passano dal ripiego: gli ids delle persone a carico e di chi riceve si leggono dalle righe, e chi non ne ha nessuna non ha un id e conta come
 * «non ne ha uno». La frase dice quello che dice la voce nuova, parola per parola, e seguita dalle rotte non lascia nessuno senza strada.
 *
 * Fonte dell'atteso: le cifre dei casi della fermata (due vendite sulle stesse persone, `Beta53FattiDeiLegatiTest`; la parte che risale,
 * `Beta51PassaggiParteCheRisaleTest`) e il conto a mano scritto in ogni test.
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

/** La voce del registro del passaggio, senza le chiavi che la .51 e la .52 non scrivevano. */
function sl53vSenzaLeChiavi(Subentro $passaggio, string $chiave, array $chiavi): array
{
    $reg = $passaggio->fresh()->registro;
    foreach (array_keys($reg[$chiave]) as $k) {
        foreach ($chiavi as $togliere) {
            unset($reg[$chiave][$k][$togliere]);
        }
    }
    $passaggio->forceFill(['registro' => $reg])->save();

    return $passaggio->fresh()->registro[$chiave][0];
}

it('due vendite sulle stesse persone ridotte da un inizio, con una voce della .52: l\'avviso dell\'annullamento dice Ugo, che non ha righe, e la parte di Ugo e Anna insieme come la voce nuova; seguito, le righe sommano la parte', function () {
    $s = b51aDl7($this, ['titolari' => [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]], 'senzaLuca' => true]);
    $s['vendita_ugo'] = b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-03-12', 50);
    $s['vendita_anna'] = b51aVendi($this, $s, 'Anna', 'Elsa', '2026-04-01', 50);
    foreach ([['Anna', -14294], ['Elsa', 14294]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita_anna']->id)))->toBe([]);
    }
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Marco', '2026-06-01'))['subentro'];
    $this->travel(1)->minutes();
    foreach (['Anna' => -7381, 'Elsa' => 7381] as $chi => $cents) {
        $riga = Saldo::where('passaggio_id', $s['vendita_anna']->id)->where('anagrafica_id', $s['persone'][$chi]->id)->sole();
        expect(sl53Errori($this->actingAs($this->user)->from('/saldi')->put(route('admin.gestionale.saldi.update', [$s['c'], $riga]), ['saldo_iniziale' => $cents, 'gestione_id' => $s['g']->id])))->toBe([]);
    }
    $this->travel(1)->minutes();

    $nuovo = FermataDopoScrivi::avvisoRidottaAnnullando($inizio->fresh()->registro['fermata_ridotta'][0]);
    expect($nuovo)->toEndWith(' I saldi del Wallet legati alla vendita del 12 marzo 2026 e alla vendita del 1 aprile 2026 sommano oggi € 73,81 a credito di Anna e € 73,81 a debito di Elsa, e Ugo non ne ha; la parte che torna è € 296,27 a credito di Ugo e Anna insieme e € 296,27 a debito di Elsa. Annullare il passaggio non li cambia: correggi dal Wallet i saldi che si modificano, e per chi non ne ha uno registra la differenza con un saldo legato a uno di quei passaggi.');

    // La voce come la scriveva la .52: i nomi delle persone a carico, non i loro ids, e la sola cifra di prima.
    $voce = sl53vSenzaLeChiavi($inizio, 'fermata_ridotta', ['parti_prima', 'persone_ids']);
    expect($voce)->not->toHaveKey('persone_ids')->and($voce['persone'])->toBe(['Ugo', 'Anna']);
    $vecchio = FermataDopoScrivi::avvisoRidottaAnnullando($voce);
    expect($vecchio)->toBe($nuovo);

    // Seguito dalle rotte: Ugo registra la sua parte sulla sua vendita, Anna ed Elsa correggono le loro righe. Le somme sono la parte che torna.
    expect(sl53Errori(sl53Annulla($this, $s, $inizio, 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita_ugo'], ['Ugo' => -15333], $vecchio))->toBe([])
        ->and(sl53SeguiIFatti($this, $s, $s['vendita_anna'], ['Anna' => -14294, 'Elsa' => 29627], $vecchio))->toBe([]);
    expect(sl53Legate($s, $s['vendita_ugo']))->toBe(['Ugo' => -15333])
        ->and(sl53Legate($s, $s['vendita_anna']))->toBe(['Anna' => -14294, 'Elsa' => 29627]);
});

it('la parte che risale, con una voce della .52 senza le parti per persona: l\'avviso dell\'annullamento della fine dice che Elsa non ha righe e le due strade come la voce nuova; seguito, le righe tornano a € 30,58', function () {
    $s = fds50Scenario([['Ugo', 'proprietario', 100]]);
    fclPersona($s, 'Marco');
    fclPersona($s, 'Carlo');
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($this, $s, '2026-09-30');
    $this->travel(1)->minutes();
    $s['vendita'] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    $this->travel(1)->minutes();
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    $fine = fds50Registra($this, $s, b51aIntFine($s, '2026-12-01'))['subentro'];
    $this->travel(1)->minutes();
    // Ugo ha una riga libera alla parte nuova; Elsa non ne ha.
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', -6115, (int) $s['vendita']->id)))->toBe([]);
    $this->travel(1)->minutes();

    $nuovo = FermataDopoScrivi::avvisoRisaleAnnullando($fine->fresh()->registro['fermata_risale'][0]);
    expect($nuovo)->toEndWith(' Il saldo del Wallet legato alla vendita del 1 ottobre 2026 è oggi di € 61,15 a credito di Ugo, e Elsa non ne ha; la parte che torna è € 30,58 a credito di Ugo e € 30,58 a debito di Elsa. Annullare il passaggio non lo cambia: correggilo dal Wallet, e per chi non ne ha uno registra la differenza con un saldo legato alla vendita del 1 ottobre 2026.');

    $voce = sl53vSenzaLeChiavi($fine, 'fermata_risale', ['parti_prima', 'parti_dopo']);
    expect($voce)->not->toHaveKey('parti_prima');
    $vecchio = FermataDopoScrivi::avvisoRisaleAnnullando($voce);
    expect($vecchio)->toBe($nuovo);

    // Seguito: Ugo corregge la sua riga, Elsa registra la sua dalla porta; nessun rifiuto, e le righe tornano a € 30,58.
    expect(sl53Errori(sl53Annulla($this, $s, $fine, 'Registrata per sbaglio, la locazione continua')))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -3058, 'Elsa' => 3058], $vecchio))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
});
