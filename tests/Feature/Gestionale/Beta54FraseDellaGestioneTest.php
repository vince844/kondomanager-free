<?php

/**
 * 1.11.0-beta.54 — la parola «gestione» nelle frasi dei passaggi non si ripete davanti al nome «Gestione ordinaria».
 *
 * «Gestione ordinaria» è il nome che il programma dà alla gestione di base di ogni condominio nuovo (`CondominioService`): le frasi del pannello, del
 * motivo del cancello, dello storico, del conguaglio e dell'avviso di «Annulla il conguaglio» scrivevano «sulla gestione» davanti al nome, e un
 * condominio con la gestione di base leggeva «sulla gestione Gestione ordinaria». Ora ogni frase nomina la gestione con `FrasiObbligati::gestione()`:
 * le frasi sono le stesse di prima, tranne la parola ripetuta.
 *
 * Lo scenario è quello della vendita dopo la fine «Scrivi» (`fds50Scenario`): Ugo vende a Elsa il 1/11, la fermata è di € 60,16 a carico di Ugo, la
 * coppia della fine di Ines è di € 181,48. Il nome della gestione cambia da un caso all'altro.
 *
 * Fonte dell'atteso: l'italiano scritto a mano; le cifre sono quelle della fermata dopo «Scrivi» della .50 (`FermataDopoScriviTest`).
 *
 * Che cosa NON copre: le altre famiglie di frasi (la fine di un conduttore, l'inizio, il rientro: stesso aiutante, provato dalla guardia sul sorgente
 * `FraseDellaGestioneTest`); le note degli incassi (`IncassoPerContoDiTest`); le note già salvate nel database prima della beta.54.
 */

use App\Models\Gestionale\Subentro;
use App\Services\Subentro\FermataDopoScrivi;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';

/** La vendita di Ugo a Elsa il 1/11 dopo la fine «Scrivi» di Ines, sulla gestione con il nome dato. */
function fg54Vendita($test, string $nomeGestione): array
{
    sl53Amministratore($test);
    $s = fds50Scenario();
    $s['g']->update(['nome' => $nomeGestione]);
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $s['fine'] = fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($test, $s, '2026-09-30');
    $test->travel(1)->minutes();
    $r = fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-01'));

    return ['s' => $s, 'anteprima' => $r['anteprima'], 'storico' => fds50Storico($s, $r['subentro']),
        'avvisi' => FermataDopoScrivi::avvisiDelConguaglio($s['fine']->fresh())];
}

it('la gestione di base si nomina una volta sola: il pannello, il motivo, lo storico, il conguaglio e l\'avviso di «Annulla il conguaglio»', function (string $nome, string $gestione) {
    $v = fg54Vendita($this, $nome);
    $testi = [...fds50Testi($v['anteprima']), ...fds50Testi($v['storico']), ...$v['avvisi']];

    // Il pannello, che dice che cosa ha preso Ugo con la coppia della fine.
    expect($testi)->toContain("La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 181,48 sulla {$gestione} (Interno 1). "
        . 'Con questo passaggio la parte di quei giorni dal 1 novembre 2026 tocca a Elsa, ma il programma non la sposta: € 60,16 restano a carico di Ugo. '
        . 'Se vanno a Elsa, registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: € 60,16 a credito di Ugo e € 60,16 a debito di Elsa. '
        . 'Un saldo legato non occupa il posto di un riporto, e si toglie se annulli il passaggio.');
    // Il motivo del cancello.
    expect($testi)->toContain("€ 60,16 dei giorni dopo l'uscita di Ines restano a carico di Ugo ({$gestione}): il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Elsa con questo passaggio");
    // Lo storico dell'unità.
    expect($testi)->toContain("Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 60,16 dei giorni dal 1 novembre 2026 sono rimasti a carico di Ugo sulla {$gestione} (Interno 1), da regolare a mano con Elsa.");
    // Il conguaglio della vendita, per gestione.
    expect(collect($testi)->contains(fn (string $t) => str_starts_with($t, "Sulla {$gestione}: credito € 0,38 a Ugo, debito € 0,38 a Elsa")))->toBeTrue();
    // L'avviso di «Annulla il conguaglio»: la fermata della vendita, sulla stessa gestione.
    expect(collect($v['avvisi'])->contains(fn (string $t) => str_contains($t, "su questo conguaglio: € 60,16 dei giorni dal 1 novembre 2026 sono rimasti a carico di Ugo sulla {$gestione}, da regolare a mano con Elsa.")))->toBeTrue();

    // Mai la parola due volte davanti al nome, in nessuna frase.
    expect(collect($testi)->filter(fn (string $t) => preg_match('/gestione Gestione/iu', $t) === 1)->values()->all())->toBe([]);
})->with([
    'il nome di base che il programma dà ad ogni condominio' => ['Gestione ordinaria', 'Gestione ordinaria'],
    'un nome che comincia per la parola, minuscolo' => ['gestione riscaldamento 2026', 'gestione riscaldamento 2026'],
    'un nome senza la parola' => ['Ordinaria 2026', 'gestione Ordinaria 2026'],
    'un nome che comincia come la parola ma non lo è' => ['Gestioni 2026', 'gestione Gestioni 2026'],
]);
