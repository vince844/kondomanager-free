<?php

/**
 * 1.11.0-beta.53 (Coda 244) — la nota di solidarietà (art. 63, co. 4 disp. att. c.c.) con le righe del Wallet legate alla vendita.
 *
 * La nota dice il non pagato di chi vende al netto della sola coppia della vendita ancora nel Wallet (come dalla .51). Le righe legate a
 * quel passaggio, a carico di chi compra, non entrano nel netto: nel caso di base la parte che spostano viene dalla coppia della fine della
 * locazione (un saldo del Wallet a debito di Ugo), non dalle sue rate emesse, e toglierla direbbe € 60,16 in meno del dovuto. La nota le dice
 * a parte, dopo la parentesi della coppia: «Elsa ha inoltre a suo debito € 60,16 con un saldo scritto a mano per lo stesso passaggio.» (a suo
 * credito, se la riga è un credito). Una riga legata che un piano ha già assorbito non si dice più, come la coppia assorbita; le righe legate
 * a un altro passaggio non si dicono.
 *
 * Le cifre (misurate sullo scenario della vendita del 1/11, e dal conto delle quote): le quote emesse di Ugo sono 9 × € 70,00 = € 630,00,
 * nessuna pagata; la coppia della vendita è € 0,38 a debito di Elsa; la nota dice € 629,62 non pagati (63000 − 38).
 *
 * Che cosa NON copre: la nota della vendita con riserva d'usufrutto (le righe legate lì si dicono allo stesso modo: non provato); più righe
 * legate della stessa persona su gestioni diverse (la frase al plurale); le pagine che mostrano la nota (estratto conto, situazione debitoria),
 * che leggono `testo`; MySQL.
 */

use App\Services\Subentro\NotaSolidarieta;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

const SL53N_ELSA = 'Elsa ha inoltre a suo debito € 60,16 con un saldo scritto a mano per lo stesso passaggio.';

/** La nota della vendita data, per chi compra. */
function sl53nNota(array $s, string $chiCompra = 'Elsa', ?int $subentroId = null): array
{
    $note = app(NotaSolidarieta::class)->per($s['c'], $s['persone'][$chiCompra]);
    $nota = collect($note)->firstWhere('subentro_id', $subentroId ?? (int) $s['vendita']->id);
    expect($nota)->not->toBeNull('Nessuna nota di solidarietà per ' . $chiCompra . ': ' . json_encode($note, JSON_UNESCAPED_UNICODE));

    return $nota;
}

it('la riga legata a carico di chi compra non entra nel netto: la cifra resta € 629,62 e la nota la dice a parte', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);

    $nota = sl53nNota($s);

    expect($nota['coppia_cents'])->toBe(38)
        ->and($nota['residuo_lordo_cents'])->toBe(63000)
        ->and($nota['residuo_uscente_cents'])->toBe(62962)
        ->and(str_contains($nota['testo'], SL53N_ELSA))->toBeTrue($nota['testo']);
});

it('la frase dice il saldo scritto a mano per lo stesso passaggio dopo la parentesi della coppia, e la parentesi resta quella del conguaglio', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);

    expect(sl53nNota($s)['testo'])->toBe('Elsa risponde in solido con Ugo per i contributi di Interno 1 (Int. 1) relativi agli esercizi 2026 e 2025 (art. 63 co. 4 disp. att. c.c.). '
        . 'Nessuna quota è intestata a Elsa per quel periodo: a nome di Ugo risultano oggi € 629,62 non pagati su questa unità (€ 630,00 nelle rate, di cui € 0,38 già a debito di Elsa con il conguaglio del 1 novembre 2026). '
        . SL53N_ELSA . ' Chi paga in forza della solidarietà ha regresso verso il venditore, salvo diverso accordo (Cass. 11199/2021).');
});

it('una gamba sola legata (la riga di Elsa, senza quella di Ugo): la nota la dice lo stesso, e la cifra non cambia', function () {
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);

    $nota = sl53nNota($s);

    expect($nota['residuo_uscente_cents'])->toBe(62962)
        ->and(str_contains($nota['testo'], SL53N_ELSA))->toBeTrue($nota['testo']);
});

it('assorbita da un piano, la riga legata non si dice più (come la coppia assorbita, la nota torna lorda)', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);
    expect(str_contains(sl53nNota($s)['testo'], SL53N_ELSA))->toBeTrue(sl53nNota($s)['testo']);

    sl53SecondoPiano($s);
    $nota = sl53nNota($s);

    // Il «Conguaglio 2026» ha preso anche la coppia della vendita: la nota torna a € 630,00 (decisione della .51 sulla coppia assorbita).
    expect($nota['residuo_uscente_cents'])->toBe(63000)
        ->and($nota['coppia_cents'])->toBe(0)
        ->and(str_contains($nota['testo'], 'saldo scritto a mano'))->toBeFalse($nota['testo']);
});

it('una vendita dopo non conta le righe legate alla prima: la nota della seconda non le dice, quella della prima sì', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);
    $prima = (int) $s['vendita']->id;
    $seconda = fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Luca', '2026-12-01'))['subentro'];

    $notaLuca = sl53nNota($s, 'Luca', (int) $seconda->id);

    expect(str_contains($notaLuca['testo'], 'saldo scritto a mano'))->toBeFalse($notaLuca['testo'])
        ->and(str_contains(sl53nNota($s, 'Elsa', $prima)['testo'], SL53N_ELSA))->toBeTrue(sl53nNota($s, 'Elsa', $prima)['testo']);
});

it('una riga legata a credito di chi compra (l\'entrata supera la spesa nei suoi giorni) si dice «a suo credito»', function () {
    // Lo scenario di G1 della .50: la vendita del 1/10 si ferma su € 29,26 a credito di Elsa; le righe legate: Ugo +2926, Elsa −2926.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Elsa']);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-09-30');
    $s['vendita'] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    foreach ([['Ugo', 2926], ['Elsa', -2926]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id)))->toBe([]);
    }

    $nota = sl53nNota($s);

    // Le quote emesse di Ugo non pagate: € 420,02 (misurato sullo scenario; la vendita non ha coppia a debito di Elsa). La riga legata non le cambia.
    expect($nota['residuo_uscente_cents'])->toBe(42002)
        ->and($nota['residuo_lordo_cents'])->toBe(42002)
        ->and(str_contains($nota['testo'], 'Elsa ha inoltre a suo credito € 29,26 con un saldo scritto a mano per lo stesso passaggio.'))->toBeTrue($nota['testo']);
});
